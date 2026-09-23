<?php

namespace App\Domain\Marking\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Delivery\Models\CandidatePaperItem;
use App\Domain\Marking\Enums\MarkSource;
use App\Domain\Marking\Models\ItemMark;

/**
 * Whether two examiners' marks for one item can stand on their own, or need a third opinion (exam
 * phase, step 20): within the threshold, their average becomes the item's final mark; beyond it,
 * the item is left pending until an adjudicator's mark — which is itself the final mark — arrives.
 */
final class FinaliseItemMark
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(CandidatePaperItem $item): void
    {
        $marks = ItemMark::query()->where('cand_paper_item_id', $item->id)
            ->where('candidate_exam_id', $item->candidate_exam_id)
            ->get()->keyBy(fn (ItemMark $m): string => $m->source->value);

        $first = $marks->get(MarkSource::Examiner1->value);
        $second = $marks->get(MarkSource::Examiner2->value);

        if ($first === null || $second === null || $marks->has(MarkSource::Final->value) || $marks->has(MarkSource::Adjudicator->value)) {
            return;
        }

        $threshold = (float) config('exam.marking.adjudication_threshold_fraction') * $first->max_marks;
        $difference = abs($first->marks_awarded - $second->marks_awarded);

        if ($difference > $threshold) {
            // Left pending: RecordAdjudication will write the deciding mark.
            return;
        }

        $average = round(($first->marks_awarded + $second->marks_awarded) / 2, 2);

        ItemMark::query()->create([
            'candidate_exam_id' => $item->candidate_exam_id,
            'cand_paper_item_id' => $item->id,
            'source' => MarkSource::Final,
            'marks_awarded' => $average,
            'max_marks' => $first->max_marks,
            'marked_by' => null,
            'marked_at' => now(),
        ]);

        $this->audit->record('marking.item_finalised', 'cand_paper_item', $item->id, null, ['marks_awarded' => $average, 'source' => 'final'], null, null, $item->candidateExam->examination->branch_id);
    }
}
