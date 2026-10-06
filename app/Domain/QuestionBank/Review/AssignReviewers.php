<?php

namespace App\Domain\QuestionBank\Review;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\QuestionBank\Enums\VersionStatus;
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
        private readonly ReviewLevels $levels,
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
        // With one level, anyone already asked this round counts, whatever level they were asked at.
        $askedAtLevel = $this->levels->single() ? $round->count() : $round->where('stage', $stage->value)->count();
        $wanted = $this->needed($stage) - $askedAtLevel;
        if ($wanted < 1) {
            return [];
        }

        // Nobody reviews the same question twice in a round, whatever the level.
        $taken = $round->pluck('reviewer_id')->all();
        $assignments = [];

        // The reviewer the author asked for at this level comes first, while they may still review
        // it; the assignment is recorded as the author's. Anyone else needed is the least busy.
        $chosenId = $stage === ReviewStage::Subject ? $version->subject_reviewer_id : $version->academic_reviewer_id;
        if ($chosenId !== null && ! in_array($chosenId, $taken, true)) {
            $chosen = User::query()->where('is_active', true)->find($chosenId);
            if ($chosen instanceof User && $this->pool->allows($chosen, $version, $stage)) {
                $assignments[] = $this->create($version, $chosen, User::query()->find($version->author_id), $stage, automatic: false);
                $taken[] = $chosen->id;
            }
        }

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

    /**
     * How many reviews a level needs: kmu-cms decides for the first; the second is one, or none when
     * kmu-cms turns the QBank / academic review off.
     */
    public function needed(ReviewStage $stage): int
    {
        return $stage === ReviewStage::Subject ? $this->settings->reviewsRequired() : ($this->settings->academicReview() ? 1 : 0);
    }

    /** A named reviewer, chosen by somebody who may assign reviewers. */
    public function to(User $actor, QuestionVersion $version, User $reviewer, ReviewStage $stage = ReviewStage::Subject): ReviewAssignment
    {
        $this->authoriseAssigning($actor, $version);

        if (! $this->pool->allows($reviewer, $version, $stage)) {
            throw ValidationException::withMessages(['reviewer_id' => 'That person cannot do the '.mb_strtolower($this->levels->label($stage)).' of this question — they wrote it, do not hold that review right, or it is outside their campus or exam access.']);
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

    /**
     * KMU: the approving authority reviews the question themselves, without being asked first. With
     * one level of review, an approver who has not reviewed this round takes the review on.
     */
    public function mayTakeOn(User $approver, QuestionVersion $version): bool
    {
        return $this->levels->single()
            && in_array($version->status, [VersionStatus::Submitted, VersionStatus::UnderReview], true)
            && $this->access->allows($approver, 'qbank.question.approve', $this->target($version))
            && $this->pool->allows($approver, $version)
            && ! $this->currentRound($version)->contains('reviewer_id', $approver->id);
    }

    /** The approver's own review of the question, assigned by themselves (see mayTakeOn). */
    public function takeOn(User $approver, QuestionVersion $version): ReviewAssignment
    {
        if (! $this->mayTakeOn($approver, $version)) {
            throw new AuthorizationException('You cannot review this question.');
        }

        return $this->create($version, $approver, $approver, ReviewStage::Subject, automatic: false);
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
        $allowed = $this->access->allows($actor, 'qbank.review.assign', $this->target($version));

        if (! $allowed) {
            throw new AuthorizationException('You cannot assign reviewers for this question.');
        }
    }

    private function target(QuestionVersion $version): ScopeTarget
    {
        return new ScopeTarget($version->branch_id, $version->programme_id, $version->professional_id, $version->course_id);
    }
}
