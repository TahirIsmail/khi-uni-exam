<?php

namespace App\Domain\QuestionBank\Review;

use App\Domain\QuestionBank\Models\ChecklistItem;
use Illuminate\Validation\ValidationException;

/**
 * The item-writing checklist a reviewer works through. Which rules apply depends on the kind of
 * question (the option rules mean nothing for an essay), and the required ones must all pass before
 * a question can be approved (blueprint 9.2).
 *
 * @phpstan-type ChecklistAnswer array{code: string, pass: bool, note: string|null}
 */
final class ChecklistRules
{
    /**
     * The rules for a question of this family, in the order a reviewer reads them.
     *
     * @return list<ChecklistItem>
     */
    public function items(?string $family = null): array
    {
        return array_values(ChecklistItem::query()
            ->where('is_active', true)
            ->when($family !== null, fn ($query) => $query->where(fn ($q) => $q->whereNull('applies_to')->orWhere('applies_to', $family)))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * Checks what the reviewer ticked: every rule that applies must be answered, and nothing that
     * does not apply may be sent.
     *
     * @param  array<int|string, mixed>  $input
     * @return list<ChecklistAnswer>
     */
    public function normalise(array $input, ?string $family): array
    {
        $applies = [];
        foreach ($this->items($family) as $item) {
            $applies[$item->code] = $item;
        }

        $answers = [];
        foreach ($input as $row) {
            if (! is_array($row) || ! isset($row['code'])) {
                throw ValidationException::withMessages(['checklist' => 'The checklist was not sent correctly.']);
            }

            $code = (string) $row['code'];
            if (! isset($applies[$code])) {
                throw ValidationException::withMessages(['checklist' => 'The checklist has an item that does not apply to this question.']);
            }
            if (isset($answers[$code])) {
                throw ValidationException::withMessages(['checklist' => 'The checklist answers the same item twice.']);
            }

            $note = isset($row['note']) && is_string($row['note']) && trim($row['note']) !== '' ? mb_substr(trim($row['note']), 0, 500) : null;
            $answers[$code] = ['code' => $code, 'pass' => (bool) ($row['pass'] ?? false), 'note' => $note];
        }

        $unanswered = array_diff(array_keys($applies), array_keys($answers));
        if ($unanswered !== []) {
            throw ValidationException::withMessages(['checklist' => 'Answer every item of the checklist ('.count($unanswered).' left).']);
        }

        return array_map(fn (string $code): array => $answers[$code], array_keys($applies));
    }

    /**
     * The required rules this checklist marks as failed, in words, so an approver can say why a
     * question cannot be approved yet.
     *
     * @param  array<int, mixed>  $checklist
     * @return list<string>
     */
    public function failedRequired(array $checklist): array
    {
        $required = [];
        foreach ($this->items() as $item) {
            if ($item->is_required) {
                $required[$item->code] = $item->text;
            }
        }

        $failed = [];
        foreach ($checklist as $row) {
            if (! is_array($row) || ! isset($row['code'])) {
                continue;
            }
            $code = (string) $row['code'];
            if (isset($required[$code]) && ! ($row['pass'] ?? false)) {
                $failed[$code] = $required[$code];
            }
        }

        return array_values($failed);
    }
}
