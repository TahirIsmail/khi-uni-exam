<?php

namespace App\Domain\Paper\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Exam\Models\Examination;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\Paper\Models\Paper;
use App\Domain\Paper\Models\PaperComment;
use App\Domain\Paper\Models\PaperItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What the committee leaves on a paper while it moderates it: a comment, general or on one item,
 * open until somebody marks it resolved. Finalising is refused while any comment is open (the
 * database enforces the moderating window too).
 */
final class PaperComments
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
    ) {}

    public function add(User $user, Examination $examination, Paper $paper, ?PaperItem $item, string $body): PaperComment
    {
        $this->authorise($user, $examination, 'view a paper to comment on it', ['exam.view', 'exam.approve']);
        $this->mustBeModerating($paper);
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 2000) {
            throw ValidationException::withMessages(['body' => 'Write a comment of up to 2000 characters.']);
        }
        if ($item !== null && $item->paper_id !== $paper->id) {
            throw ValidationException::withMessages(['item_id' => 'That question is not in this paper.']);
        }

        $comment = PaperComment::query()->create([
            'paper_id' => $paper->id,
            'item_id' => $item?->id,
            'body' => $body,
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        $this->audit->record('paper.comment_added', 'paper', $paper->id, null, ['comment_id' => $comment->id, 'item_id' => $item?->id], null, $user, $examination->branch_id);

        return $comment;
    }

    public function resolve(User $user, Examination $examination, Paper $paper, PaperComment $comment, bool $resolved): PaperComment
    {
        $this->authorise($user, $examination, 'resolve comments on this paper', ['exam.approve']);
        $this->mustBeModerating($paper);
        if ($comment->paper_id !== $paper->id) {
            throw ValidationException::withMessages(['comment' => 'That comment is not on this paper.']);
        }

        return DB::transaction(function () use ($user, $examination, $paper, $comment, $resolved): PaperComment {
            $comment = PaperComment::query()->where('paper_id', $paper->id)->lockForUpdate()->findOrFail($comment->id);
            $to = $resolved ? 'resolved' : 'open';
            if ($comment->status !== $to) {
                $comment->update(['status' => $to, 'resolved_by' => $resolved ? $user->id : null, 'resolved_at' => $resolved ? now() : null]);
                $this->audit->record($resolved ? 'paper.comment_resolved' : 'paper.comment_reopened', 'paper', $paper->id, null, ['comment_id' => $comment->id], null, $user, $examination->branch_id);
            }

            return $comment;
        });
    }

    /**
     * @param  list<string>  $anyOf
     */
    private function authorise(User $user, Examination $examination, string $what, array $anyOf): void
    {
        $target = new ScopeTarget($examination->branch_id, $examination->programme_id, $examination->professional_id, $examination->course_id);
        foreach ($anyOf as $permission) {
            if ($this->access->allows($user, $permission, $target)) {
                return;
            }
        }

        throw new AuthorizationException("You cannot {$what}.");
    }

    private function mustBeModerating(Paper $paper): void
    {
        if (! $paper->status->isModerating()) {
            throw ValidationException::withMessages(['paper' => 'Comments can only be added or changed while the paper is being moderated.']);
        }
    }
}
