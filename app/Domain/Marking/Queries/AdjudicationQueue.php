<?php

namespace App\Domain\Marking\Queries;

use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Exam\Models\Examination;
use App\Domain\Marking\Models\ItemMark;
use Illuminate\Support\Collection;

/**
 * Items where two examiners have marked but disagreed beyond the threshold, and nobody has
 * adjudicated yet.
 */
final class AdjudicationQueue
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forExamination(Examination $examination): array
    {
        $attempts = CandidateExam::query()->where('examination_id', $examination->id)
            ->where('status', AttemptStatus::Submitted)->with(['candidate', 'items.paperItem'])->get();

        $marks = ItemMark::query()->whereIn('candidate_exam_id', $attempts->pluck('id'))->get()
            ->groupBy('cand_paper_item_id');

        $rows = [];
        foreach ($attempts as $attempt) {
            foreach ($attempt->items as $item) {
                /** @var Collection<int, ItemMark> $itemMarks */
                $itemMarks = $marks->get($item->id, collect());
                if (! FinalMark::isPending($itemMarks, true)) {
                    continue;
                }
                $bySource = $itemMarks->keyBy(fn (ItemMark $m): string => $m->source->value);
                if (! $bySource->has('examiner_1') || ! $bySource->has('examiner_2')) {
                    continue;
                }

                $rows[] = [
                    'attemptId' => $attempt->id,
                    'itemId' => $item->id,
                    'candidateNo' => $attempt->candidate->candidate_no,
                    'position' => $item->position,
                    'marks' => (float) $item->paperItem->marks,
                    'examiner1' => (float) $bySource->get('examiner_1')->marks_awarded,
                    'examiner2' => (float) $bySource->get('examiner_2')->marks_awarded,
                ];
            }
        }

        return $rows;
    }
}
