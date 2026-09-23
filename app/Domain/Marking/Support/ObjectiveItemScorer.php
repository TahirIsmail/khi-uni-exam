<?php

namespace App\Domain\Marking\Support;

use App\Domain\QuestionBank\Enums\ItemAnswer;
use App\Domain\QuestionBank\Models\QuestionItem;
use App\Domain\QuestionBank\Models\QuestionOption;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use Illuminate\Support\Collection;

/**
 * Scores one objective item against its key (exam phase, step 20) — used both the moment an
 * attempt is submitted (App\Domain\Marking\Actions\AutoMarkAttempt) and when a paper item is
 * re-keyed after the exam (step 21, App\Domain\Results\Actions\RekeyPaperItem): re-keying does not
 * touch the reusable question-bank record, so `$forceCorrectOptionId` lets a single/multi-select
 * item be rescored as if a different option were correct, without changing
 * `qb_question_options.is_correct` itself.
 */
final class ObjectiveItemScorer
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function score(QuestionType $type, QuestionVersion $version, array $payload, float $maxMarks, ?int $forceCorrectOptionId = null): float
    {
        if ($type->has_items) {
            return match ($type->item_answer) {
                ItemAnswer::Position => $this->scoreOrder($version->items, $payload['order'] ?? null, $maxMarks),
                default => $this->scoreItems($type, $version->items, $payload['items'] ?? [], $maxMarks),
            };
        }

        if ($type->has_accepted_answers) {
            return $maxMarks * TypedAnswerMatch::bestFraction($version->answers->where('item_id', null), $payload['text'] ?? null);
        }

        return $this->scoreOptions($type, $version->options->where('item_id', null), $payload['selected'] ?? null, $maxMarks, $forceCorrectOptionId);
    }

    /**
     * @param  Collection<int, QuestionOption>  $options
     * @param  mixed  $selected
     */
    public function scoreOptions(QuestionType $type, $options, $selected, float $maxMarks, ?int $forceCorrectOptionId = null): float
    {
        if (! is_array($selected)) {
            return 0.0;
        }
        $selectedIds = array_map('intval', $selected);
        sort($selectedIds);

        $correctIds = $forceCorrectOptionId !== null
            ? [$forceCorrectOptionId]
            : $options->where('is_correct', true)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        if ($selectedIds === $correctIds) {
            return $maxMarks;
        }

        if ($forceCorrectOptionId !== null || ! $type->supports_partial_credit) {
            return 0.0;
        }

        $byId = $options->keyBy('id');
        $fraction = 0.0;
        foreach ($selectedIds as $id) {
            $option = $byId->get($id);
            if ($option !== null && $option->weight !== null) {
                $fraction += $option->weight;
            }
        }

        return max(0.0, min($maxMarks, $maxMarks * $fraction));
    }

    /**
     * @param  Collection<int, QuestionItem>  $items
     * @param  array<int|string, mixed>  $given
     */
    private function scoreItems(QuestionType $type, $items, array $given, float $maxMarks): float
    {
        $equalShare = $items->count() > 0 ? 1 / $items->count() : 0.0;
        $total = 0.0;

        foreach ($items as $item) {
            $share = $item->marks_fraction ?? $equalShare;
            $answer = $given[$item->id] ?? null;

            $earned = match ($type->item_answer) {
                ItemAnswer::Boolean => $answer === $item->is_true ? 1.0 : 0.0,
                ItemAnswer::Option => is_numeric($answer) && (int) $answer === $item->correct_option_id ? 1.0 : 0.0,
                ItemAnswer::Text => TypedAnswerMatch::bestFraction($item->answers, is_string($answer) ? $answer : null),
                default => 0.0,
            };

            $total += $share * $earned;
        }

        return $maxMarks * $total;
    }

    /**
     * @param  Collection<int, QuestionItem>  $items
     * @param  mixed  $order
     */
    private function scoreOrder($items, $order, float $maxMarks): float
    {
        if (! is_array($order)) {
            return 0.0;
        }

        $correctSequence = $items->sortBy('sort_order')->values();
        $equalShare = $correctSequence->count() > 0 ? 1 / $correctSequence->count() : 0.0;
        $total = 0.0;

        foreach ($correctSequence as $index => $item) {
            $atThisPosition = isset($order[$index]) ? (int) $order[$index] : null;
            if ($atThisPosition === (int) $item->id) {
                $total += $item->marks_fraction ?? $equalShare;
            }
        }

        return $maxMarks * $total;
    }
}
