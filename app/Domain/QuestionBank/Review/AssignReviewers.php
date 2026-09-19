<?php

namespace App\Domain\QuestionBank\Review;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\ReviewAssignment;
use App\Models\User;
use App\Support\Cms\CmsSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who reviews a submitted question. Review has two levels (ReviewStage): when an author sends a
 * question it goes to as many department / subject reviewers as kmu-cms asks for; when they have all
 * reviewed it, it goes to one QBank / academic reviewer — somebody who has not reviewed it already.
 * Both are assigned automatically, round-robin by open load, and somebody with "Assign Reviewers"
 * can also name a reviewer or hand the job to somebody else.
 */
final class AssignReviewers
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly ReviewerPool $pool,
        private readonly CmsSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Fills the version up to the number of reviews kmu-cms asks for. Quietly assigns nobody when
     * the campus has no one else who may review this course — the approver's queue shows that.
     *
     * @return list<ReviewAssignment>
     */
    public function auto(QuestionVersion $version, ?User $actor = null, ReviewStage $stage = ReviewStage::Subject): array
    {
        $round = $this->currentRound($version);
        $wanted = $this->needed($stage) - $round->where('stage', $stage->value)->count();
        if ($wanted < 1) {
            return [];
        }

        // Nobody reviews the same question twice in a round, whatever the level.
        $taken = $round->pluck('reviewer_id')->all();
        $assignments = [];

        foreach ($this->pool->forVersion($version, $stage) as $candidate) {
            if (count($assignments) >= $wanted) {
                break;
            }
            if (in_array($candidate['user']->id, $taken, true)) {
                continue;
            }

            $assignments[] = $this->create($version, $candidate['user'], $actor, $stage, automatic: true);
        }

        return $assignments;
    }

    /** How many reviews a level needs: kmu-cms decides for the first, the second is always one. */
    public function needed(ReviewStage $stage): int
    {
        return $stage === ReviewStage::Subject ? $this->settings->reviewsRequired() : 1;
    }

    /** A named reviewer, chosen by somebody who may assign reviewers. */
    public function to(User $actor, QuestionVersion $version, User $reviewer, ReviewStage $stage = ReviewStage::Subject): ReviewAssignment
    {
        $this->authoriseAssigning($actor, $version);

        if (! $this->pool->allows($reviewer, $version, $stage)) {
            throw ValidationException::withMessages(['reviewer_id' => 'That person cannot do the '.mb_strtolower($stage->label()).' of this question — they wrote it, do not hold that review right, or it is outside their campus or exam access.']);
        }
        $open = ReviewAssignment::query()
            ->where('version_id', $version->id)
            ->where('reviewer_id', $reviewer->id)
            ->where('status', 'open')
            ->exists();
        if ($open) {
            throw ValidationException::withMessages(['reviewer_id' => 'They are already reviewing this question.']);
        }

        return $this->create($version, $reviewer, $actor, $stage, automatic: false);
    }

    /** Takes the job back, so it can be given to somebody else. */
    public function cancel(User $actor, ReviewAssignment $assignment, string $reason): ReviewAssignment
    {
        $version = $assignment->version;
        $this->authoriseAssigning($actor, $version);

        if (! $assignment->isOpen()) {
            throw ValidationException::withMessages(['assignment' => 'That review has already been submitted or cancelled.']);
        }

        $assignment->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason]);

        $this->audit->record('qbank.review.cancelled', 'review_assignment', $assignment->id, ['status' => 'open'], [
            'status' => 'cancelled',
            'reviewer_id' => $assignment->reviewer_id,
        ], $reason, $actor, $assignment->branch_id);

        return $assignment->fresh() ?? $assignment;
    }

    /** Open assignments cancelled because the question went back to its author. */
    public function cancelOpenFor(QuestionVersion $version, User $actor, string $reason, ?int $exceptId = null): void
    {
        $open = ReviewAssignment::query()->where('version_id', $version->id)->where('status', 'open')->orderBy('id')->get();

        foreach ($open as $assignment) {
            if ($exceptId !== null && $assignment->id === $exceptId) {
                continue;
            }

            $assignment->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            $this->audit->record('qbank.review.cancelled', 'review_assignment', $assignment->id, ['status' => 'open'], [
                'status' => 'cancelled',
                'reviewer_id' => $assignment->reviewer_id,
            ], $reason, $actor, $assignment->branch_id);
        }
    }

    /**
     * The assignments of the round of review the version is in now. An earlier round — before the
     * author changed the question — is history: those reviewers are asked again.
     *
     * @return Collection<int, ReviewAssignment>
     */
    public function currentRound(QuestionVersion $version): Collection
    {
        return ReviewAssignment::query()
            ->where('version_id', $version->id)
            ->where('round', $version->review_round)
            ->whereIn('status', ['open', 'submitted'])
            ->orderBy('id')
            ->get();
    }

    private function create(QuestionVersion $version, User $reviewer, ?User $actor, ReviewStage $stage, bool $automatic): ReviewAssignment
    {
        return DB::transaction(function () use ($version, $reviewer, $actor, $stage, $automatic): ReviewAssignment {
            $assignment = ReviewAssignment::query()->create([
                'version_id' => $version->id,
                'question_id' => $version->question_id,
                'branch_id' => $version->branch_id,
                'reviewer_id' => $reviewer->id,
                'stage' => $stage->value,
                'round' => $version->review_round,
                'assigned_by' => $automatic ? null : $actor?->id,
                'status' => 'open',
                'due_at' => now()->addDays($this->settings->reviewDays()),
                'assigned_at' => now(),
            ]);

            $this->audit->record('qbank.review.assigned', 'review_assignment', $assignment->id, null, [
                'version_id' => $version->id,
                'reviewer_id' => $reviewer->id,
                'stage' => $stage->value,
                'due_at' => $assignment->due_at?->toIso8601String(),
                'automatic' => $automatic,
            ], null, $actor, $version->branch_id);

            return $assignment;
        });
    }

    private function authoriseAssigning(User $actor, QuestionVersion $version): void
    {
        $allowed = $this->access->allows($actor, 'qbank.review.assign', new ScopeTarget(
            $version->branch_id,
            $version->programme_id,
            $version->professional_id,
            $version->course_id,
        ));

        if (! $allowed) {
            throw new AuthorizationException('You cannot assign reviewers for this question.');
        }
    }
}
