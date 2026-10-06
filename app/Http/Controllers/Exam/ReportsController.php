<?php

namespace App\Http\Controllers\Exam;

use App\Domain\Identity\ActiveBranch;
use App\Domain\Reports\Queries\CandidateStatement;
use App\Domain\Reports\Queries\CohortKey;
use App\Domain\Reports\Queries\TabulationSheet;
use App\Domain\Reports\Support\SheetCsv;
use App\Http\Controllers\Controller;
use App\Support\Cms\CmsAcademic;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Result sheets: the class tabulation sheet, one candidate's detailed marks certificate, and the
 * CSV of the sheet.
 *
 * Both sheets say **Provisional** on them, and mean it: this module knows only the computer-based
 * paper, while a professional result adds the practical, the viva and the internal assessment.
 */
class ReportsController extends Controller
{
    public function __construct(private readonly ActiveBranch $activeBranch) {}

    public function index(Request $request, CmsAcademic $academic): Response
    {
        $branchId = $this->branchId($request);

        return Inertia::render('reports/Index', [
            'programmes' => $academic->programmes($branchId),
            'years' => $academic->yearsAndTerms($branchId),
            'intakes' => $academic->intakes($branchId),
        ]);
    }

    public function tabulation(Request $request, TabulationSheet $sheet, CmsAcademic $academic): Response
    {
        $cohort = $this->cohort($request);

        return Inertia::render('reports/Tabulation', [
            'cohort' => $this->describe($cohort, $academic),
            'sheet' => $sheet->for($cohort),
        ]);
    }

    public function tabulationCsv(Request $request, TabulationSheet $sheet, SheetCsv $csv): StreamedResponse
    {
        $cohort = $this->cohort($request);

        return $csv->stream(
            $sheet->for($cohort),
            sprintf('tabulation-%d-%d-%s.csv', $cohort->programmeId, $cohort->professionalId, $cohort->intakeId ?? 'all'),
        );
    }

    public function statement(Request $request, string $candidateNo, CandidateStatement $statement, CmsAcademic $academic): Response
    {
        $cohort = $this->cohort($request);
        $found = $statement->for($cohort, $candidateNo);

        abort_if($found === null, 404, 'Nobody of that candidate number sat anything in this group.');

        return Inertia::render('reports/Statement', [
            'cohort' => $this->describe($cohort, $academic),
            'statement' => $found,
        ]);
    }

    private function cohort(Request $request): CohortKey
    {
        $input = $request->validate([
            'programme_id' => ['required', 'integer'],
            'professional_id' => ['required', 'integer'],
            'intake_id' => ['nullable', 'integer'],
            'term_id' => ['nullable', 'integer'],
        ]);

        // Each route carries its own permission; the campus is what keeps one from reading
        // another's numbers by typing them.
        return new CohortKey(
            $this->branchId($request),
            (int) $input['programme_id'],
            (int) $input['professional_id'],
            isset($input['intake_id']) ? (int) $input['intake_id'] : null,
            isset($input['term_id']) ? (int) $input['term_id'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(CohortKey $cohort, CmsAcademic $academic): array
    {
        $programmes = collect($academic->programmes($cohort->branchId))->keyBy('id');
        $intakes = collect($academic->intakes($cohort->branchId))->keyBy('id');

        return [
            'programmeId' => $cohort->programmeId,
            'professionalId' => $cohort->professionalId,
            'intakeId' => $cohort->intakeId,
            'termId' => $cohort->termId,
            'programme' => $programmes->get($cohort->programmeId)['name'] ?? null,
            'year' => $academic->yearName($cohort->branchId, $cohort->professionalId, $cohort->termId),
            'intake' => $intakes->get($cohort->intakeId)['name'] ?? null,
        ];
    }

    private function branchId(Request $request): int
    {
        return $this->activeBranch->id($request->user('web')) ?? abort(403, 'You do not work in any campus.');
    }
}
