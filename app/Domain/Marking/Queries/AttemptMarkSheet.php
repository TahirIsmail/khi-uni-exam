<?php

namespace App\Domain\Marking\Queries;

use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Delivery\Models\CandidatePaperItem;
use App\Domain\Marking\Models\ItemMark;
use App\Domain\QuestionBank\Models\QuestionType;
use Illuminate\Support\Collection;

/**
 * One candidate's whole paper as it stands: every item, what it is worth, what it was given, and
 * where that mark came from.
 *
 * This is what makes a machine's mark answerable. The marking queue only ever shows an examiner what
 * is waiting for them, which is right for getting through a pile of essays and wrong for the moment
 * a candidate says question 14 was marked unfairly. From here an examiner can open any item at all,
 * including one the computer settled against the sealed key, and record their own mark over it.
 */
final class AttemptMarkSheet
{
    /**
     * @return array{
     *     candidateNo: string,
     *     name: string,
     *     items: list<array<string, mixed>>,
     * }
     */
    public function for(CandidateExam $attempt): array
    {
        $attempt->loadMissing(['candidate', 'items.paperItem', 'examination']);

        $types = QuestionType::query()->whereIn('id', $attempt->items->pluck('paperItem.question_type_id')->unique())
            ->get()->keyBy('id');

        $marks = ItemMark::query()->where('candidate_exam_id', $attempt->id)->get()
            ->groupBy('cand_paper_item_id');

        $rows = $attempt->items
            ->sortBy(fn (CandidatePaperItem $item): int => $item->position)
            ->map(function (CandidatePaperItem $item) use ($types, $marks, $attempt): array {
                $type = $types->get($item->paperItem->question_type_id);

                /** @var Collection<int, ItemMark> $itemMarks */
                $itemMarks = $marks->get($item->id, collect());
                $final = FinalMark::of($itemMarks, $attempt->examination->require_double_marking);

                return [
                    'itemId' => $item->id,
                    'position' => $item->position,
                    'typeName' => $type?->name,
                    'marks' => (float) $item->paperItem->marks,
                    'awarded' => $final?->marks_awarded,
                    'source' => $final?->source->value,
                    'sourceLabel' => $final?->source->label(),
                    // A machine mark nobody has overturned is the one an examiner may want to look
                    // at; the screen says so rather than making them work it out from the source.
                    'isMachineMark' => $final !== null && $final->source->value === 'auto',
                    'awaiting' => $final === null,
                ];
            });

        return [
            'candidateNo' => (string) $attempt->candidate->candidate_no,
            'name' => (string) $attempt->candidate->name,
            'items' => array_values($rows->all()),
        ];
    }
}
