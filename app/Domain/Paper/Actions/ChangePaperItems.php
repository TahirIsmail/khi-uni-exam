<?php

namespace App\Domain\Paper\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Exam\Models\Examination;
use App\Domain\Paper\Models\Paper;
use App\Domain\Paper\Models\PaperItem;
use App\Domain\Paper\PaperItems;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The setter's own changes to a paper, one question at a time: adding a question to a row, swapping
 * one for another from the same row, taking one out, and locking one so a new draw leaves it be.
 * Each is checked against the same pool the automatic draw uses, and audited.
 */
final class ChangePaperItems
{
    public function __construct(
        private readonly PaperGuard $guard,
        private readonly PaperItems $items,
        private readonly AuditLogger $audit,
    ) {}

    public function add(User $user, Examination $examination, Paper $paper, int $nodeId, int $typeId, float $marks, ?string $section, int $questionId): PaperItem
    {
        $this->guard->authorise($user, $examination);
        $this->guard->mustBeEditable($examination, $paper);

        return DB::transaction(function () use ($user, $examination, $paper, $nodeId, $typeId, $marks, $section, $questionId): PaperItem {
            Paper::query()->whereKey($paper->id)->lockForUpdate()->firstOrFail();

            $slot = $this->items->slot($examination, $nodeId, $typeId, $marks, $section);
            if ($this->items->filled($paper, $slot) >= $slot->count) {
                throw ValidationException::withMessages(['slot' => 'This row already has all the questions the blueprint asks for. Swap one instead, or change the blueprint.']);
            }
            $candidate = $this->items->candidate($examination, $slot, $questionId);
            $this->items->mustNotBeInPaper($paper, $questionId);

            $item = $this->items->insert($paper, $slot, $candidate, 'manual', $user->id);
            $this->items->renumber($examination, $paper);
            $paper->update(['updated_by' => $user->id]);

            $this->audit->record('paper.item_added', 'paper', $paper->id, null, ['question' => (string) $candidate->public_ref, 'marks' => $slot->marks], null, $user, $examination->branch_id);

            return $item;
        });
    }

    public function swap(User $user, Examination $examination, Paper $paper, PaperItem $item, int $questionId): PaperItem
    {
        $this->guard->authorise($user, $examination);
        $this->guard->mustBeEditable($examination, $paper);

        return DB::transaction(function () use ($user, $examination, $paper, $item, $questionId): PaperItem {
            Paper::query()->whereKey($paper->id)->lockForUpdate()->firstOrFail();
            $item = PaperItem::query()->where('paper_id', $paper->id)->findOrFail($item->id);

            if ($item->is_locked) {
                throw ValidationException::withMessages(['item' => 'That question is locked. Unlock it to swap it.']);
            }

            // The new question serves the same row as the one it replaces.
            $slot = $this->items->slot($examination, $item->row_node_id, $item->question_type_id, $item->marks, $item->section_name);
            $candidate = $this->items->candidate($examination, $slot, $questionId);
            $this->items->mustNotBeInPaper($paper, $questionId);

            $old = (string) DB::table('qb_questions')->where('id', $item->question_id)->value('public_ref');
            $item->update([
                'question_id' => (int) $candidate->question_id,
                'version_id' => (int) $candidate->version_id,
                'source' => 'manual',
                'picked_by' => $user->id,
            ]);
            $paper->update(['updated_by' => $user->id]);

            $this->audit->record('paper.item_swapped', 'paper', $paper->id, ['question' => $old], ['question' => (string) $candidate->public_ref], null, $user, $examination->branch_id);

            return $item;
        });
    }

    public function remove(User $user, Examination $examination, Paper $paper, PaperItem $item): void
    {
        $this->guard->authorise($user, $examination);
        $this->guard->mustBeEditable($examination, $paper);

        DB::transaction(function () use ($user, $examination, $paper, $item): void {
            Paper::query()->whereKey($paper->id)->lockForUpdate()->firstOrFail();
            $item = PaperItem::query()->where('paper_id', $paper->id)->findOrFail($item->id);

            if ($item->is_locked) {
                throw ValidationException::withMessages(['item' => 'That question is locked. Unlock it to take it out.']);
            }

            $reference = (string) DB::table('qb_questions')->where('id', $item->question_id)->value('public_ref');
            $item->delete();
            $this->items->renumber($examination, $paper);
            $paper->update(['updated_by' => $user->id]);

            $this->audit->record('paper.item_removed', 'paper', $paper->id, ['question' => $reference], null, null, $user, $examination->branch_id);
        });
    }

    public function lock(User $user, Examination $examination, Paper $paper, PaperItem $item, bool $locked): PaperItem
    {
        $this->guard->authorise($user, $examination);
        $this->guard->mustBeEditable($examination, $paper);

        return DB::transaction(function () use ($user, $examination, $paper, $item, $locked): PaperItem {
            $item = PaperItem::query()->where('paper_id', $paper->id)->lockForUpdate()->findOrFail($item->id);

            if ($item->is_locked !== $locked) {
                $item->update(['is_locked' => $locked]);
                $reference = (string) DB::table('qb_questions')->where('id', $item->question_id)->value('public_ref');
                $this->audit->record($locked ? 'paper.item_locked' : 'paper.item_unlocked', 'paper', $paper->id, null, ['question' => $reference], null, $user, $examination->branch_id);
            }

            return $item;
        });
    }
}
