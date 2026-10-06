<?php

namespace App\Domain\QuestionBank\Review;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\PrehocAssessment;
use App\Domain\QuestionBank\Models\PrehocDecision;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\Review;
use App\Domain\QuestionBank\Models\ReviewAssignment;
use App\Domain\QuestionBank\Models\VersionStatusLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A reviewer's outcome, at either level of review (blueprint P1.8, KMU QBank mechanism). Either:
 *
 *  - "request changes": a comment is required and the question goes straight back to its author as
 *    changes-requested; the other open reviews are called off, because the question will change.
 *  - a review: a decision, the item-writing checklist and, for whoever may record it, the cognitive
 *    and difficulty level. The question moves to under-review. When every department / subject
 *    reviewer has reviewed it, it goes on to a QBank / academic reviewer. After that review, a
 *    question every reviewer accepted is stored in the QBank at once when kmu-cms says so
 *    (ApproveVersion::acceptedByReviewers); otherwise it waits for the approving authority.
 *
 * A submitted review is never edited — the database refuses it. Saying something else means a new
 * review, which is what happens after the author has made changes.
 */
final class SubmitReview
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly ChecklistRules $checklist,
        private readonly AssignReviewers $assignments,
        private readonly ApproveVersion $approve,
        private readonly ReviewLevels $levels,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $reviewer, ReviewAssignment $assignment, ReviewInput $input): Review
    {
        $version = $assignment->version;

        if ($assignment->reviewer_id !== $reviewer->id) {
            throw new AuthorizationException('That review was given to somebody else.');
        }
        if (! $assignment->isOpen()) {
            throw ValidationException::withMessages(['assignment' => 'That review has already been submitted or cancelled.']);
        }
        $stage = ReviewStage::from($assignment->stage);
        if (! $this->levels->mayReview($reviewer, $this->target($version), $stage)) {
            throw new AuthorizationException('You cannot do the '.mb_strtolower($this->levels->label($stage)).' of questions of this course.');
        }
        if (! in_array($version->status, [VersionStatus::Submitted, VersionStatus::UnderReview], true)) {
            throw ValidationException::withMessages(['status' => 'This question is not waiting for review.']);
        }
        if ($version->author_id === $reviewer->id) {
            throw new AuthorizationException('You cannot review your own question.');
        }

        // Choosing "Revise" is how a reviewer sends the question back to its author.
        if (! $input->requestsChanges() && $input->decisionId !== null
            && PrehocDecision::query()->whereKey($input->decisionId)->value('code') === 'revise') {
            $input = new ReviewInput(
                outcome: 'changes_requested',
                decisionId: $input->decisionId,
                comments: $input->comments,
                checklist: $input->checklist,
                cognitiveLevelId: $input->cognitiveLevelId,
                difficultyLevelId: $input->difficultyLevelId,
            );
        }

        $comments = $input->comments === null || trim($input->comments) === '' ? null : trim($input->comments);
        $decision = null;
        $checklist = null;

        if ($input->requestsChanges()) {
            if ($comments === null || mb_strlen($comments) < 10) {
                throw ValidationException::withMessages(['comments' => 'Say what the author has to change (at least 10 characters).']);
            }
            $decision = $input->decisionId === null ? null : $this->decision($input->decisionId);
        } else {
            $decision = $this->decision($input->decisionId);
            if ($decision->needs_comment && ($comments === null || mb_strlen($comments) < 10)) {
                throw ValidationException::withMessages(['comments' => 'This decision needs a comment saying why (at least 10 characters).']);
            }

            $checklist = $this->checklist->normalise($input->checklist, $version->type->family);
        }

        $prehoc = $this->prehocValues($reviewer, $version, $input);

        return DB::transaction(function () use ($reviewer, $assignment, $version, $input, $comments, $decision, $checklist, $prehoc, $stage): Review {
            $review = Review::query()->create([
                'assignment_id' => $assignment->id,
                'version_id' => $version->id,
                'question_id' => $version->question_id,
                'branch_id' => $version->branch_id,
                'reviewer_id' => $reviewer->id,
                'stage' => $stage->value,
                'round' => $assignment->round,
                'outcome' => $input->requestsChanges() ? 'changes_requested' : 'reviewed',
                'decision_id' => $decision?->id,
                'comments' => $comments,
                'checklist' => $checklist,
                'submitted_at' => now(),
            ]);

            $assignment->update(['status' => 'submitted', 'submitted_at' => now()]);

            if ($prehoc !== null) {
                PrehocAssessment::query()->create([
                    'version_id' => $version->id,
                    'question_id' => $version->question_id,
                    'branch_id' => $version->branch_id,
                    'review_id' => $review->id,
                    'source' => 'reviewer',
                    'cognitive_level_id' => $prehoc['cognitive_level_id'],
                    'difficulty_level_id' => $prehoc['difficulty_level_id'],
                    'estimated_p' => $prehoc['estimated_p'],
                    'decision_id' => $decision?->id,
                    'is_consolidated' => false,
                    'assessed_by' => $reviewer->id,
                    'assessed_at' => now(),
                ]);

                $this->audit->record('qbank.prehoc.recorded', 'question_version', $version->id, null, [
                    'review_id' => $review->id,
                    'cognitive_level_id' => $prehoc['cognitive_level_id'],
                    'difficulty_level_id' => $prehoc['difficulty_level_id'],
                    'estimated_p' => $prehoc['estimated_p'],
                ], null, $reviewer, $version->branch_id);
            }

            if ($input->requestsChanges()) {
                $this->moveTo($version, VersionStatus::ChangesRequested, $reviewer, $comments);
                $version->update(['decision_code' => 'revise']);
                $this->assignments->cancelOpenFor($version, $reviewer, 'The question went back to its author for changes.', $assignment->id);

                $this->audit->record('qbank.review.changes_requested', 'question_version', $version->id, ['status' => VersionStatus::Submitted->value], [
                    'status' => VersionStatus::ChangesRequested->value,
                    'review_id' => $review->id,
                ], $comments, $reviewer, $version->branch_id);

                return $review;
            }

            if ($version->status === VersionStatus::Submitted) {
                $this->moveTo($version, VersionStatus::UnderReview, $reviewer, null);
            }

            $this->audit->record('qbank.review.submitted', 'question_version', $version->id, null, [
                'review_id' => $review->id,
                'stage' => $stage->value,
                'decision' => $decision?->code,
                'failed_required' => $this->checklist->failedRequired($checklist ?? []),
            ], $comments, $reviewer, $version->branch_id);

            // When the department / subject reviews of this round are all in, the question goes on
            // to the QBank / academic review.
            // When kmu-cms turns the QBank / academic review off, the department / subject review is
            // the last level.
            $lastLevel = $stage === ReviewStage::Academic && ! $this->levels->single();
            if (($stage === ReviewStage::Subject || $this->levels->single()) && $this->subjectReviewsComplete($version)) {
                if ($this->assignments->needed(ReviewStage::Academic) > 0) {
                    $this->assignments->auto($version, $reviewer, ReviewStage::Academic);
                } else {
                    $lastLevel = true;
                }
            }

            // The last level is in: when every reviewer accepted it, it goes straight into the QBank.
            if ($lastLevel) {
                $this->approve->acceptedByReviewers($reviewer, $version->fresh() ?? $version);
            }

            return $review;
        });
    }

    private function subjectReviewsComplete(QuestionVersion $version): bool
    {
        // With one level, every review of the round counts, whatever level it was asked at.
        $round = $this->assignments->currentRound($version);
        if (! $this->levels->single()) {
            $round = $round->where('stage', ReviewStage::Subject->value);
        }

        return $round->where('status', 'open')->isEmpty()
            && $round->where('status', 'submitted')->count() >= $this->assignments->needed(ReviewStage::Subject);
    }

    /**
     * @return array{cognitive_level_id: int|null, difficulty_level_id: int|null, estimated_p: float|null}|null
     */
    private function prehocValues(User $reviewer, QuestionVersion $version, ReviewInput $input): ?array
    {
        if (! $input->hasPrehocValues()) {
            return null;
        }
        // The approving authority settles these levels when they decide, so they may record them here too.
        if (! $this->access->allows($reviewer, 'qbank.prehoc.record', $this->target($version))
            && ! $this->access->allows($reviewer, 'qbank.question.approve', $this->target($version))) {
            throw new AuthorizationException('You cannot record a pre-hoc assessment.');
        }
        if ($input->estimatedP !== null && ($input->estimatedP < 0 || $input->estimatedP > 1)) {
            throw ValidationException::withMessages(['estimated_p' => 'The expected proportion correct is between 0 and 1.']);
        }

        return [
            'cognitive_level_id' => $input->cognitiveLevelId,
            'difficulty_level_id' => $input->difficultyLevelId,
            'estimated_p' => $input->estimatedP,
        ];
    }

    private function decision(?int $decisionId): PrehocDecision
    {
        $decision = $decisionId === null ? null : PrehocDecision::query()->where('is_active', true)->find($decisionId);

        if (! $decision instanceof PrehocDecision) {
            throw ValidationException::withMessages(['decision_id' => 'Choose what should happen to this question.']);
        }

        return $decision;
    }

    private function moveTo(QuestionVersion $version, VersionStatus $to, User $actor, ?string $reason): void
    {
        $from = $version->status;
        $version->update(['status' => $to, 'updated_by' => $actor->id]);

        VersionStatusLog::query()->create([
            'version_id' => $version->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor->id,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }

    private function target(QuestionVersion $version): ScopeTarget
    {
        return new ScopeTarget($version->branch_id, $version->programme_id, $version->professional_id, $version->course_id);
    }
}
