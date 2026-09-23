<?php

namespace App\Domain\Marking\Actions;

use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Marking\Enums\MarkSource;
use App\Domain\Marking\Support\TypedAnswerMatch;
use App\Domain\QuestionBank\Enums\ItemAnswer;
use App\Domain\QuestionBank\Models\QuestionItem;
use App\Domain\QuestionBank\Models\QuestionOption;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Marking every objective item of a just-submitted attempt against the sealed key (exam phase,
 * step 20): the answer key is read here, server-side, exactly as ADR-0003 always said it would be
 * — never sent to the candidate's browser. Manually-marked items (essays) are left for an examiner.
 * Called from SubmitAttempt, inside the same transaction as the submission itself.
 */
final class AutoMarkAttempt
{
    public function __invoke(CandidateExam $attempt): void
    {
        $items = $attempt->items()->with('paperItem')->get();
        $typeIds = $items->pluck('paperItem.question_type_id')->unique()->values();
        $types = QuestionType::query()->whereIn('id', $typeIds)->get()->keyBy('id');

        $versionIds = $items->pluck('paperItem.version_id')->unique()->values();
        $versions = QuestionVersion::query()->whereIn('id', $versionIds)
            ->with(['options', 'items.answers', 'answers'])
            ->get()->keyBy('id');

        $answers = DB::table('dlv_answers_current')->where('candidate_exam_id', $attempt->id)
            ->get(['cand_paper_item_id', 'payload'])->keyBy('cand_paper_item_id');

        $rows = [];
        foreach ($items as $item) {
            $paperItem = $item->paperItem;
            $type = $types->get($paperItem->question_type_id);
            if ($type === null || $type->is_manually_marked) {
                continue;
            }

            $version = $versions->get($paperItem->version_id);
            $payload = ($row = $answers->get($item->id)) === null ? [] : (json_decode((string) $row->payload, true) ?? []);
            $marks = $version === null ? 0.0 : $this->score($type, $version, $payload, (float) $paperItem->marks);

            $rows[] = [
                'candidate_exam_id' => $attempt->id,
                'cand_paper_item_id' => $item->id,
                'source' => MarkSource::Auto->value,
                'marks_awarded' => round($marks, 2),
                'max_marks' => $paperItem->marks,
                'marked_by' => null,
                'comments' => null,
                'marked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('mrk_item_marks')->insertOrIgnore($rows);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function score(QuestionType $type, QuestionVersion $version, array $payload, float $maxMarks): float
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

        return $this->scoreOptions($type, $version->options->where('item_id', null), $payload['selected'] ?? null, $maxMarks);
    }

    /**
     * @param  Collection<int, QuestionOption>  $options
     * @param  mixed  $selected
     */
    private function scoreOptions(QuestionType $type, $options, $selected, float $maxMarks): float
    {
        if (! is_array($selected)) {
            return 0.0;
        }
        $selectedIds = array_map('intval', $selected);
        sort($selectedIds);

        $correctIds = $options->where('is_correct', true)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        if ($selectedIds === $correctIds) {
            return $maxMarks;
        }

        if (! $type->supports_partial_credit) {
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
