<?php

namespace App\Domain\QuestionBank\Queries;

use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Models\User;

/**
 * Previous / next for a question opened from the question list, whatever its filters: the questions
 * either side of it in that same list, each opened where the list itself would open it — the review
 * screen for an approver's decision, the editor for a draft they may edit, else the question. From
 * a question's history, the next one opens at its history too.
 */
final class ListNeighbours
{
    public function __construct(private readonly QuestionList $list) {}

    /**
     * @param  array<string, mixed>  $filters  the list's filters, already checked
     * @param  string  $listQuery  the list's query string, carried on so the next one has it too
     * @param  bool  $history  going from one question's history to the next
     * @return array{previous: array{url: string, reference: string}|null, next: array{url: string, reference: string}|null, position: int, total: int, label: string}|null
     */
    public function around(User $user, int $branchId, array $filters, string $listQuery, QuestionVersion $version, bool $history = false): ?array
    {
        $rows = $this->list->inOrder($user, $branchId, $filters);
        $at = array_search($version->id, array_column($rows, 'versionId'), true);
        if ($at === false) {
            return null;
        }

        $link = fn (?array $row): ?array => $row === null ? null : [
            'url' => ($history ? "/questions/{$row['questionId']}" : $this->openUrl($user, $row)).'?list='.rawurlencode($listQuery),
            'reference' => $row['reference'],
        ];

        return [
            'previous' => $link($rows[$at - 1] ?? null),
            'next' => $link($rows[$at + 1] ?? null),
            'position' => $at + 1,
            'total' => count($rows),
            'label' => 'in the list',
        ];
    }

    /**
     * Where the list opens a question (resources/js/pages/qbank/Questions.vue, mayDecide / mayEdit).
     *
     * @param  array{questionId: int, versionId: int, status: string, authorId: int}  $row
     */
    private function openUrl(User $user, array $row): string
    {
        $base = "/questions/{$row['questionId']}/versions/{$row['versionId']}";
        $mine = $row['authorId'] === $user->id;

        if ($user->can('qbank.question.approve') && ! $mine && in_array($row['status'], ['submitted', 'under_review'], true)) {
            return $base.'/review';
        }
        if (in_array($row['status'], ['draft', 'changes_requested'], true)
            && (($mine && $user->can('qbank.question.edit_own')) || $user->can('qbank.question.edit_any'))) {
            return $base.'/edit';
        }

        return $base;
    }
}
