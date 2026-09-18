<?php

namespace App\Http\Controllers\QuestionBank;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\ActiveBranch;
use App\Domain\QuestionBank\Import\CheckImport;
use App\Domain\QuestionBank\Import\CommitImport;
use App\Domain\QuestionBank\Import\SpreadsheetReader;
use App\Domain\QuestionBank\Models\ImportRow;
use App\Domain\QuestionBank\Models\QuestionImport;
use App\Domain\QuestionBank\Queries\QuestionEditorData;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importing questions from a spreadsheet: upload and check (qbank.import.run), then commit into the
 * bank as drafts (qbank.import.commit). Nothing is created until a commit, and everything stays in
 * the campus the user is working in.
 */
class ImportController extends Controller
{
    public function __construct(
        private readonly ActiveBranch $activeBranch,
        private readonly QuestionEditorData $editorData,
    ) {}

    public function index(Request $request): Response
    {
        $branchId = $this->branchId($request);

        return Inertia::render('qbank/Imports', [
            'imports' => QuestionImport::query()
                ->where('branch_id', $branchId)
                ->with('uploadedBy:id,name')
                ->latest('id')
                ->paginate(20)
                ->through(fn (QuestionImport $import): array => [
                    'id' => $import->id,
                    'name' => $import->original_name,
                    'format' => $import->format,
                    'status' => $import->status,
                    'rowsTotal' => $import->rows_total,
                    'rowsValid' => $import->rows_valid,
                    'rowsInvalid' => $import->rows_invalid,
                    'rowsCommitted' => $import->rows_committed,
                    'uploadedBy' => $import->uploadedBy?->name,
                    'uploadedAt' => $import->created_at?->toIso8601String(),
                ]),
            'canCommit' => $request->user()->can('qbank.import.commit'),
            'columns' => SpreadsheetReader::COLUMNS,
            ...$this->editorData->forCreate($request->user(), $branchId),
        ]);
    }

    public function store(Request $request, CheckImport $check): RedirectResponse
    {
        $branchId = $this->branchId($request);

        $input = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,tsv,xlsx,xls'],
            'course_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'node_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'type_id' => ['nullable', 'integer', 'min:1', 'max:255'],
        ], [
            'file.mimes' => 'Upload a CSV or Excel file (.csv, .tsv, .xlsx).',
            'file.max' => 'The file must be 10 MB or smaller.',
        ]);

        $import = $check($request->user(), $branchId, $request->file('file'), [
            'course_id' => isset($input['course_id']) ? (int) $input['course_id'] : null,
            'node_id' => isset($input['node_id']) ? (int) $input['node_id'] : null,
            'type_id' => isset($input['type_id']) ? (int) $input['type_id'] : null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':valid of :total rows are ready to import.', ['valid' => $import->rows_valid, 'total' => $import->rows_total])]);

        return to_route('imports.show', $import->id);
    }

    public function show(Request $request, QuestionImport $import): Response
    {
        $this->authoriseImport($request, $import);

        $filter = $request->validate(['only' => ['nullable', 'in:valid,invalid,duplicate,committed']]);

        return Inertia::render('qbank/ImportPreview', [
            'import' => [
                'id' => $import->id,
                'name' => $import->original_name,
                'format' => $import->format,
                'status' => $import->status,
                'rowsTotal' => $import->rows_total,
                'rowsValid' => $import->rows_valid,
                'rowsInvalid' => $import->rows_invalid,
                'rowsCommitted' => $import->rows_committed,
                'committable' => $import->isCommittable(),
                'uploadedAt' => $import->created_at?->toIso8601String(),
                'committedAt' => $import->committed_at?->toIso8601String(),
            ],
            'only' => $filter['only'] ?? '',
            'rows' => $import->rows()
                ->when(($filter['only'] ?? '') !== '', fn ($query) => $query->where('status', $filter['only']))
                ->paginate(50)
                ->withQueryString()
                ->through(fn (ImportRow $row): array => [
                    'id' => $row->id,
                    'rowNumber' => $row->row_number,
                    'status' => $row->status,
                    'raw' => $row->raw,
                    'parsed' => $row->parsed,
                    'errors' => collect($row->errors ?? [])->flatten()->values()->all(),
                    'warnings' => $row->warnings ?? [],
                    'questionId' => $row->question_id,
                    'versionId' => $row->version_id,
                ]),
            'canCommit' => $request->user()->can('qbank.import.commit'),
        ]);
    }

    public function commit(Request $request, QuestionImport $import, CommitImport $commit): RedirectResponse
    {
        $this->authoriseImport($request, $import);

        $result = $commit($request->user(), $import);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':created questions were added as drafts.', ['created' => $result->rows_committed])]);

        return to_route('imports.show', $import->id);
    }

    public function destroy(Request $request, QuestionImport $import, AuditLogger $audit): RedirectResponse
    {
        $this->authoriseImport($request, $import);

        if ($import->status === 'committed') {
            abort(422, 'A committed import cannot be discarded; archive the questions it created instead.');
        }

        $import->update(['status' => 'discarded']);
        Storage::disk($import->disk)->delete($import->path);
        $audit->record('qbank.import.discarded', 'question_import', $import->id, null, ['file' => $import->original_name], null, $request->user(), $import->branch_id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The file was discarded.')]);

        return to_route('imports.index');
    }

    /** A ready-made file with the column names and one example row per common type. */
    public function template(): StreamedResponse
    {
        $rows = [
            SpreadsheetReader::COLUMNS,
            [
                'sba', 'CVS-101', 'Acute coronary syndrome', 'Physiology', '', 'A 54-year-old man has crushing chest pain radiating to the jaw for 40 minutes.',
                'Which investigation is most useful first?', 'An ECG is immediate and guides reperfusion.', '1', '0', 'apply', 'moderate',
                'ECG | Chest radiograph | Echocardiogram | Coronary angiography', 'A', '', '', 'Harrison, 21st ed p. 1875', 'ECG, cardiology',
            ],
            [
                'mtf', 'CVS-101', 'Acute coronary syndrome', '', '', 'Regarding the management of an inferior myocardial infarction:',
                '', '', '3', '0', '', '', '', '', '', 'Aspirin reduces mortality = true | Nitrates are given in right ventricular infarction = false | Reperfusion within 90 minutes is the aim = true', '', '',
            ],
            [
                'short_answer', 'CVS-101', 'Acute coronary syndrome', '', '', 'Which enzyme is measured to confirm myocardial injury?',
                '', '', '1', '0', 'recall', '', '', '', 'troponin | troponin I | troponin T', '', '', '',
            ],
            [
                'numerical', 'CVS-101', 'Acute coronary syndrome', '', '', 'What is the normal arterial pH?',
                '', '', '1', '0', 'recall', 'easy', '', '', '7.40 ± 0.05', '', '', '',
            ],
        ];

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            foreach ($rows as $row) {
                fputcsv($out, $row, escape: '');
            }
            fclose($out);
        }, 'kmu-question-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authoriseImport(Request $request, QuestionImport $import): void
    {
        abort_unless((int) $import->branch_id === $this->branchId($request), 404);
    }

    private function branchId(Request $request): int
    {
        return $this->activeBranch->id($request->user()) ?? abort(403, 'You do not work in any campus.');
    }
}
