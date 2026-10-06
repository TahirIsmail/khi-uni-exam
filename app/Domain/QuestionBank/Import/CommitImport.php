<?php

namespace App\Domain\QuestionBank\Import;

use App\Domain\Audit\AuditLogger;
use App\Domain\QuestionBank\Actions\CreateQuestionDraft;
use App\Domain\QuestionBank\Models\ImportRow;
use App\Domain\QuestionBank\Models\QuestionImport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts the rows that passed into the bank as drafts (blueprint 13.4). Rows with a problem are left
 * alone, so a file can be corrected and uploaded again; a committed row remembers the question it
 * created, so anything imported can be traced back to its line in the file.
 *
 * Imported questions are drafts, never approved: they go through review like anything else.
 */
final class CommitImport
{
    public function __construct(
        private readonly RowParser $parser,
        private readonly CreateQuestionDraft $createDraft,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $user, QuestionImport $import): QuestionImport
    {
        if ($import->status !== 'checked') {
            throw ValidationException::withMessages(['import' => 'This file has already been '.$import->status.'.']);
        }
        if ($import->rows_valid === 0) {
            throw ValidationException::withMessages(['import' => 'No row in this file can be imported yet.']);
        }

        $committed = 0;
        $failed = [];

        foreach ($import->rows()->where('status', 'valid')->cursor() as $row) {
            /** @var ImportRow $row */
            ['content' => $content, 'errors' => $errors] = $this->parser->parse($row->raw, $import->branch_id, $import->defaults ?? []);

            if ($content === null) {
                // The academic structure may have changed since the file was checked.
                $row->update(['status' => 'invalid', 'errors' => $errors === [] ? ['row' => ['This row can no longer be imported.']] : $errors]);
                $failed[] = $row->row_number;

                continue;
            }

            try {
                $version = DB::transaction(fn () => ($this->createDraft)($user, $import->branch_id, $content, source: 'import', importRowId: $row->id));
            } catch (ValidationException $problem) {
                $row->update(['status' => 'invalid', 'errors' => $problem->errors()]);
                $failed[] = $row->row_number;

                continue;
            }

            $row->update([
                'status' => 'committed',
                'question_id' => $version->question_id,
                'version_id' => $version->id,
            ]);
            $committed++;
        }

        $import->update([
            'status' => 'committed',
            'rows_committed' => $committed,
            'rows_valid' => $import->rows()->where('status', 'valid')->count(),
            'rows_invalid' => $import->rows()->whereIn('status', ['invalid', 'duplicate'])->count(),
            'committed_at' => now(),
            'committed_by' => $user->id,
        ]);

        $this->audit->record('qbank.import.committed', 'question_import', $import->id, null, [
            'file' => $import->original_name,
            'created' => $committed,
            'rows_that_failed' => $failed,
        ], null, $user, $import->branch_id);

        return $import->fresh() ?? $import;
    }
}
