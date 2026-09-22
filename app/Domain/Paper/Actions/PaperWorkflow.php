<?php

namespace App\Domain\Paper\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Blueprint\Enums\BlueprintStatus;
use App\Domain\Exam\Models\Examination;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\Paper\Enums\PaperStatus;
use App\Domain\Paper\Models\Paper;
use App\Domain\Paper\PaperFingerprint;
use App\Domain\Paper\Queries\PaperData;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A paper's way through moderation and locking (exam phase, step 4):
 *
 *  - Submit: by whoever built it, once it is complete and free of the defects worth stopping over.
 *  - Approve: by somebody else with the approving right — nobody moderates a paper they started —
 *    once it is still sound (the bank can change under a submitted paper's questions, so this is
 *    checked again).
 *  - Return: the moderator sends it back to draft with what to change.
 *  - Finalise: seals it — a fingerprint of its questions, their order and their marks — once every
 *    comment is resolved. From here the database itself refuses any further change to it.
 *  - Publish: marks it ready for delivery. A separate right from finalising, on purpose: the person
 *    who locked the paper need not be the one who releases it.
 *
 * Each step is audited, and the database allows no other order of steps.
 */
final class PaperWorkflow
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly PaperData $data,
        private readonly PaperFingerprint $fingerprint,
        private readonly AuditLogger $audit,
    ) {}

    public function submit(User $user, Examination $examination, Paper $paper): Paper
    {
        $this->authorise($user, $examination, 'exam.submit', 'submit papers for this course');

        return DB::transaction(function () use ($user, $examination, $paper): Paper {
            $paper = $this->lock($paper, PaperStatus::Draft, 'Only a paper being built can be submitted.');
            $this->mustBeSound($examination, $paper);

            $paper->update([
                'status' => PaperStatus::Submitted,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'return_reason' => null,
                'updated_by' => $user->id,
            ]);

            $this->audit->record('paper.submitted', 'paper', $paper->id, ['status' => PaperStatus::Draft->value], ['status' => PaperStatus::Submitted->value], null, $user, $examination->branch_id);

            return $paper;
        });
    }

    public function approve(User $user, Examination $examination, Paper $paper): Paper
    {
        $this->authorise($user, $examination, 'exam.approve', 'approve papers for this course');

        return DB::transaction(function () use ($user, $examination, $paper): Paper {
            $paper = $this->lock($paper, PaperStatus::Submitted, 'Only a paper awaiting moderation can be approved.');

            if ($paper->created_by === $user->id || $paper->submitted_by === $user->id) {
                throw new AuthorizationException('You cannot approve a paper you started or submitted.');
            }
            // Comments do not block approval itself — the moderator may approve with a note still
            // open — but finalising does: nothing is locked in while a comment is unresolved.
            $this->mustBeSound($examination, $paper);

            $paper->update(['status' => PaperStatus::Approved, 'approved_by' => $user->id, 'approved_at' => now(), 'updated_by' => $user->id]);

            $this->audit->record('paper.approved', 'paper', $paper->id, ['status' => PaperStatus::Submitted->value], ['status' => PaperStatus::Approved->value], null, $user, $examination->branch_id);

            return $paper;
        });
    }

    public function returnToDraft(User $user, Examination $examination, Paper $paper, string $reason): Paper
    {
        $this->authorise($user, $examination, 'exam.approve', 'send papers back for this course');
        $reason = $this->reason($reason, 'Say what has to change (at least 10 characters).');

        return DB::transaction(function () use ($user, $examination, $paper, $reason): Paper {
            $paper = Paper::query()->whereKey($paper->id)->lockForUpdate()->firstOrFail();
            if (! in_array($paper->status, [PaperStatus::Submitted, PaperStatus::Approved], true)) {
                throw ValidationException::withMessages(['paper' => 'Only a paper awaiting or already moderated can be sent back.']);
            }
            $from = $paper->status;

            $paper->update([
                'status' => PaperStatus::Draft,
                'submitted_by' => null,
                'submitted_at' => null,
                'approved_by' => null,
                'approved_at' => null,
                'return_reason' => $reason,
                'updated_by' => $user->id,
            ]);

            $this->audit->record('paper.returned', 'paper', $paper->id, ['status' => $from->value], ['status' => PaperStatus::Draft->value], $reason, $user, $examination->branch_id);

            return $paper;
        });
    }

    public function finalise(User $user, Examination $examination, Paper $paper): Paper
    {
        $this->authorise($user, $examination, 'exam.finalise', 'finalise papers for this course');

        return DB::transaction(function () use ($user, $examination, $paper): Paper {
            $paper = $this->lock($paper, PaperStatus::Approved, 'Only an approved paper can be finalised.');

            if ($this->openComments($paper) > 0) {
                throw ValidationException::withMessages(['paper' => 'There are open comments. Resolve them before finalising.']);
            }
            $this->mustBeSound($examination, $paper);

            $hash = $this->fingerprint->of($paper);
            $paper->update(['status' => PaperStatus::Finalised, 'finalised_by' => $user->id, 'finalised_at' => now(), 'content_hash' => $hash, 'updated_by' => $user->id]);

            $this->audit->record('paper.finalised', 'paper', $paper->id, ['status' => PaperStatus::Approved->value], ['status' => PaperStatus::Finalised->value, 'fingerprint' => $hash], null, $user, $examination->branch_id);

            return $paper;
        });
    }

    public function publish(User $user, Examination $examination, Paper $paper): Paper
    {
        $this->authorise($user, $examination, 'exam.publish', 'publish papers for this course');

        return DB::transaction(function () use ($user, $examination, $paper): Paper {
            $paper = $this->lock($paper, PaperStatus::Finalised, 'Only a finalised paper can be published.');

            $paper->update(['status' => PaperStatus::Published, 'published_by' => $user->id, 'published_at' => now(), 'updated_by' => $user->id]);

            $this->audit->record('paper.published', 'paper', $paper->id, ['status' => PaperStatus::Finalised->value], ['status' => PaperStatus::Published->value], null, $user, $examination->branch_id);

            return $paper;
        });
    }

    private function authorise(User $user, Examination $examination, string $permission, string $what): void
    {
        if (! $this->access->allows($user, $permission, new ScopeTarget($examination->branch_id, $examination->programme_id, $examination->professional_id, $examination->course_id))) {
            throw new AuthorizationException("You cannot {$what}.");
        }
    }

    private function lock(Paper $paper, PaperStatus $expected, string $message): Paper
    {
        /** @var Paper $locked */
        $locked = Paper::query()->whereKey($paper->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== $expected) {
            throw ValidationException::withMessages(['paper' => $message]);
        }

        return $locked;
    }

    private function mustBeSound(Examination $examination, Paper $paper): void
    {
        $report = $this->data->report($examination, $paper);
        if (! $report['isComplete'] || $report['blockers'] !== []) {
            throw ValidationException::withMessages(['paper' => $report['blockers'][0] ?? 'The paper does not yet match the blueprint.']);
        }
        // The blueprint's own approval can be undone independently of the paper; a paper cannot move
        // forward while it is not (BlueprintWorkflow::reopen refuses this once the paper is finalised,
        // but it can still happen earlier in the paper's own moderation).
        $blueprint = DB::table('exm_blueprints')->where('examination_id', $examination->id)->value('status');
        if ($blueprint !== BlueprintStatus::Approved->value) {
            throw ValidationException::withMessages(['paper' => 'The blueprint is no longer approved. It has to be approved again before the paper can move forward.']);
        }
    }

    private function openComments(Paper $paper): int
    {
        return DB::table('exm_paper_comments')->where('paper_id', $paper->id)->where('status', 'open')->count();
    }

    private function reason(string $reason, string $message): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => $message]);
        }

        return mb_substr($reason, 0, 500);
    }
}
