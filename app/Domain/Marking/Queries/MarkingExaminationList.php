<?php

namespace App\Domain\Marking\Queries;

use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Exam\Models\Examination;
use Illuminate\Support\Facades\DB;

/**
 * Examinations on this campus with something submitted to mark — the starting list for the
 * Marking screen.
 */
final class MarkingExaminationList
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forBranch(int $branchId): array
    {
        $examIds = DB::table('cand_candidate_exams')->where('cand_candidate_exams.status', AttemptStatus::Submitted->value)
            ->join('exm_examinations', 'exm_examinations.id', '=', 'cand_candidate_exams.examination_id')
            ->where('exm_examinations.branch_id', $branchId)
            ->distinct()->pluck('cand_candidate_exams.examination_id');

        $examinations = Examination::query()->whereIn('id', $examIds)->orderByDesc('id')->get();

        $submittedCounts = DB::table('cand_candidate_exams')->whereIn('examination_id', $examIds)
            ->where('cand_candidate_exams.status', AttemptStatus::Submitted->value)
            ->selectRaw('examination_id, count(*) as total') // raw-sql-reviewed: no user input, plain aggregate
            ->groupBy('examination_id')->pluck('total', 'examination_id');

        return array_values($examinations->map(fn (Examination $exam): array => [
            'id' => $exam->id,
            'reference' => $exam->public_ref,
            'title' => $exam->title,
            'requireDoubleMarking' => $exam->require_double_marking,
            'submittedCount' => (int) ($submittedCounts[$exam->id] ?? 0),
        ])->all());
    }
}
