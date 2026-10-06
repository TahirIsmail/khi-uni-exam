<?php

namespace App\Domain\Marking\Queries;

use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Marking\Models\ItemMark;

/**
 * One attempt's score as it stands: the final mark of every item (FinalMark decides which counts),
 * out of the paper's marks, and whether that reaches the examination's pass percentage. An item
 * still waiting for an examiner leaves the result pending rather than counting as nothing.
 */
final class AttemptScore
{
    /**
     * @return array{awarded: float, total: float, percent: float, passMarks: float, passed: bool, pending: int}
     */
    public function of(CandidateExam $attempt): array
    {
        $attempt->loadMissing(['items.paperItem', 'examination']);
        $marks = ItemMark::query()->where('candidate_exam_id', $attempt->id)->get()->groupBy('cand_paper_item_id');

        $awarded = 0.0;
        $total = 0.0;
        $pending = 0;
        foreach ($attempt->items as $item) {
            $total += (float) $item->paperItem->marks;
            $final = FinalMark::of($marks->get($item->id, collect()), $attempt->examination->require_double_marking);
            if ($final === null) {
                $pending++;

                continue;
            }
            $awarded += (float) $final->marks_awarded;
        }

        $awarded = max(0.0, round($awarded, 2));
        $percent = $total > 0 ? round($awarded / $total * 100, 1) : 0.0;
        $passMarks = round($total * (float) $attempt->examination->pass_percentage / 100, 2);

        return [
            'awarded' => $awarded,
            'total' => round($total, 2),
            'percent' => $percent,
            'passMarks' => $passMarks,
            'passed' => $pending === 0 && $awarded >= $passMarks,
            'pending' => $pending,
        ];
    }
}
