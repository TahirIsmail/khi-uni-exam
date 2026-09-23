<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Delivery\Models\CandidatePaperItem;
use App\Domain\Paper\Models\Paper;
use App\Domain\Paper\Models\PaperItem;
use Illuminate\Support\Facades\DB;

/**
 * Copies a paper's items into this candidate's own attempt, in this candidate's own order — done
 * exactly once, the moment an attempt starts, and never redone: the database refuses any later
 * change to it (migration 2026_09_30_000102). Nothing about the shuffle is remembered anywhere else,
 * so it cannot be worked out or repeated.
 */
final class AssignPaperItems
{
    public function __invoke(CandidateExam $attempt, Paper $paper): void
    {
        $items = PaperItem::query()->where('paper_id', $paper->id)->orderBy('position')->get();

        $order = $items->all();
        if ($paper->shuffle_questions) {
            $order = $this->securelyShuffled($order);
        }

        $rows = [];
        foreach (array_values($order) as $index => $item) {
            $rows[] = [
                'candidate_exam_id' => $attempt->id,
                'paper_item_id' => $item->id,
                'position' => $index + 1,
                'option_order' => $paper->shuffle_options ? $this->shuffledOptionOrder($item) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        CandidatePaperItem::query()->insert($rows);
    }

    /**
     * @return list<int>|null null when the item has no options to order (an essay, a numeric answer).
     */
    private function shuffledOptionOrder(PaperItem $item): ?array
    {
        $options = DB::table('qb_question_options')
            ->where('version_id', $item->version_id)
            ->whereNull('item_id')
            ->orderBy('sort_order')
            ->get(['id', 'is_position_locked']);

        if ($options->isEmpty()) {
            return null;
        }

        // Locked options (kept, e.g. "None of the above") stay in their slot; the rest are shuffled
        // among themselves and poured back into the remaining slots, in order.
        $unlockedIds = $this->securelyShuffled($options->where('is_position_locked', false)->pluck('id')->all());

        $order = [];
        $next = 0;
        foreach ($options as $option) {
            $order[] = $option->is_position_locked ? $option->id : $unlockedIds[$next++];
        }

        return $order;
    }

    /**
     * A Fisher-Yates shuffle on `random_int()`, not `shuffle()`: which candidate gets which order is
     * exactly the sort of thing worth keeping unguessable, the same as the project's rule for tokens.
     *
     * @template T
     *
     * @param  array<int, T>  $items
     * @return list<T>
     */
    private function securelyShuffled(array $items): array
    {
        $items = array_values($items);
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return array_values($items);
    }
}
