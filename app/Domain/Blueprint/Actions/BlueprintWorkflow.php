<?php

namespace App\Domain\Blueprint\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Blueprint\BlueprintChecker;
use App\Domain\Blueprint\BlueprintFingerprint;
use App\Domain\Blueprint\Enums\BlueprintStatus;
use App\Domain\Blueprint\Models\Blueprint;
use App\Domain\Exam\Enums\ExaminationStatus;
use App\Domain\Exam\Models\Examination;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A blueprint's way through approval: submitted by the person who wrote it, then approved — or sent
 * back — by somebody else.
 *
 *  - Submit: the blueprint has to be sound (see BlueprintChecker).
 *  - Approve: by a person with the approving right who neither wrote nor submitted it. The blueprint
 *    is fingerprinted, and the examination is ready for its paper.
 *  - Return: the approver sends it back to draft with what to change.
 *  - Reopen: an approved blueprint goes back to draft, with the reason, when it turns out to be wrong.
 *
 * Each step is audited, and the database allows no other order of steps.
 */
final class BlueprintWorkflow
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly BlueprintChecker $checker,
        private readonly BlueprintFingerprint $fingerprint,
        private readonly AuditLogger $audit,
    ) {}

    public function submit(User $user, Examination $examination): Blueprint
    {
        $this->authorise($user, $examination, 'exam.blueprint.manage', 'submit blueprints for this course');

        return DB::transaction(function () use ($user, $examination): Blueprint {
            $blueprint = $this->lock($examination, BlueprintStatus::Draft, 'Only a blueprint in preparation can be submitted.');
            $this->mustBeSound($examination, $blueprint);

            $blueprint->update([
                'status' => BlueprintStatus::Submitted,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'return_reason' => null,
                'updated_by' => $user->id,
            ]);

            $this->audit->record('blueprint.submitted', 'blueprint', $blueprint->id, ['status' => BlueprintStatus::Draft->value], ['status' => BlueprintStatus::Submitted->value], null, $user, $examination->branch_id);

            return $blueprint;
        });
    }

    public function approve(User $user, Examination $examination): Blueprint
    {
        $this->authorise($user, $examination, 'exam.blueprint.approve', 'approve blueprints for this course');

        return DB::transaction(function () use ($user, $examination): Blueprint {
            $blueprint = $this->lock($examination, BlueprintStatus::Submitted, 'Only a submitted blueprint can be approved.');

            if ($blueprint->created_by === $user->id || $blueprint->submitted_by === $user->id) {
                throw new AuthorizationException('You cannot approve a blueprint you wrote or submitted.');
            }
            $this->mustBeSound($examination, $blueprint);

            $hash = $this->fingerprint->of($examination, $blueprint);
            $blueprint->update([
                'status' => BlueprintStatus::Approved,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'approved_hash' => $hash,
                'updated_by' => $user->id,
            ]);
            $examination->update(['status' => ExaminationStatus::BlueprintApproved, 'updated_by' => $user->id]);

            $this->audit->record('blueprint.approved', 'blueprint', $blueprint->id, ['status' => BlueprintStatus::Submitted->value], [
                'status' => BlueprintStatus::Approved->value,
                'fingerprint' => $hash,
            ], null, $user, $examination->branch_id);

            return $blueprint;
        });
    }

    public function returnToDraft(User $user, Examination $examination, string $reason): Blueprint
    {
        $this->authorise($user, $examination, 'exam.blueprint.approve', 'send blueprints back for this course');
        $reason = $this->reason($reason, 'Say what has to change (at least 10 characters).');

        return DB::transaction(function () use ($user, $examination, $reason): Blueprint {
            $blueprint = $this->lock($examination, BlueprintStatus::Submitted, 'Only a submitted blueprint can be sent back.');

            $blueprint->update([
                'status' => BlueprintStatus::Draft,
                'submitted_by' => null,
                'submitted_at' => null,
                'return_reason' => $reason,
                'updated_by' => $user->id,
            ]);

            $this->audit->record('blueprint.returned', 'blueprint', $blueprint->id, ['status' => BlueprintStatus::Submitted->value], ['status' => BlueprintStatus::Draft->value], $reason, $user, $examination->branch_id);

            return $blueprint;
        });
    }

    public function reopen(User $user, Examination $examination, string $reason): Blueprint
    {
        $this->authorise($user, $examination, 'exam.blueprint.approve', 'reopen blueprints for this course');
        $reason = $this->reason($reason, 'Say why the approved blueprint has to change (at least 10 characters).');

        return DB::transaction(function () use ($user, $examination, $reason): Blueprint {
            $blueprint = $this->lock($examination, BlueprintStatus::Approved, 'Only an approved blueprint can be reopened.');

            $lockedPaper = DB::table('exm_papers')->where('examination_id', $examination->id)
                ->whereIn('status', ['finalised', 'published'])->exists();
            if ($lockedPaper) {
                throw ValidationException::withMessages(['blueprint' => 'This examination\'s paper has already been finalised. The blueprint cannot be reopened underneath it.']);
            }

            $previous = ['status' => BlueprintStatus::Approved->value, 'approved_by' => $blueprint->approved_by, 'fingerprint' => $blueprint->approved_hash];

            $blueprint->update([
                'status' => BlueprintStatus::Draft,
                'submitted_by' => null,
                'submitted_at' => null,
                'approved_by' => null,
                'approved_at' => null,
                'approved_hash' => null,
                'return_reason' => $reason,
                'updated_by' => $user->id,
            ]);
            $examination->update(['status' => ExaminationStatus::Draft, 'updated_by' => $user->id]);

            $this->audit->record('blueprint.reopened', 'blueprint', $blueprint->id, $previous, ['status' => BlueprintStatus::Draft->value], $reason, $user, $examination->branch_id);

            return $blueprint;
        });
    }

    private function authorise(User $user, Examination $examination, string $permission, string $what): void
    {
        if (! $this->access->allows($user, $permission, new ScopeTarget($examination->branch_id, $examination->programme_id, $examination->professional_id, $examination->course_id))) {
            throw new AuthorizationException("You cannot {$what}.");
        }
    }

    private function lock(Examination $examination, BlueprintStatus $expected, string $message): Blueprint
    {
        /** @var Blueprint $blueprint */
        $blueprint = Blueprint::query()->where('examination_id', $examination->id)->lockForUpdate()->firstOrFail();
        if ($blueprint->status !== $expected) {
            throw ValidationException::withMessages(['blueprint' => $message]);
        }

        return $blueprint;
    }

    private function mustBeSound(Examination $examination, Blueprint $blueprint): void
    {
        $blockers = $this->checker->check($examination, $blueprint)['blockers'];
        if ($blockers !== []) {
            throw ValidationException::withMessages(['blueprint' => $blockers[0]]);
        }
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
