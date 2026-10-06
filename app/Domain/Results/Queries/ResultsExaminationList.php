<?php

namespace App\Domain\Results\Queries;

use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Exam\Models\Examination;
use App\Domain\Results\Enums\PublicationStatus;
use App\Domain\Results\Models\ResultPublication;
use Illuminate\Support\Facades\DB;

/**
 * Examinations on this campus with something submitted, for the Results screen — the same
 * starting shape as Marking's own list, extended with where the approve/publish workflow stands.
 */
final class ResultsExaminationList
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
        $publications = ResultPublication::query()->whereIn('examination_id', $examIds)->get()->keyBy('examination_id');

        $submittedCounts = DB::table('cand_candidate_exams')->whereIn('examination_id', $examIds)
            ->where('cand_candidate_exams.status', AttemptStatus::Submitted->value)
            ->selectRaw('examination_id, count(*) as total') // raw-sql-reviewed: no user input, plain aggregate
            ->groupBy('examination_id')->pluck('total', 'examination_id');

        return array_values($examinations->map(function (Examination $exam) use ($publications, $submittedCounts): array {
            $publication = $publications->get($exam->id);
            $status = $publication === null ? PublicationStatus::Draft : $publication->status;

            return [
                'id' => $exam->id,
                'reference' => $exam->public_ref,
                'title' => $exam->title,
                'submittedCount' => (int) ($submittedCounts[$exam->id] ?? 0),
                'status' => $status->value,
                'statusLabel' => $status->label(),
            ];
        })->all());
    }
}
