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
use App\Domain\QuestionBank\Models\VersionStatusLog;
use App\Models\User;
use App\Support\Cms\CmsSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The approval gate (blueprint 9.2, KMU QBank mechanism). The approving authority is the one who
 * decides (KMU: "the Approver should be the only authority to review the question"), so they need
 * not wait for reviewers: any reviews that are in are advice. A version is approved only when:
 *
 *  - it is submitted for review (reviewed or not);
 *  - no required item of the item-writing checklist was marked as failed by a reviewer;
 *  - the approver's consolidated decision is an "accept" one;
 *  - the approver is not the author (separation of duty);
 *  - and when the reviewers disagreed, the approver says why this is the answer.
 *
 * The consolidated pre-hoc values are stored as their own row and copied onto the version, which is
 * what a blueprint draws on later — never the author's proposal.
 */
final class ApproveVersion
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly ChecklistRules $checklist,
        private readonly ActivateVersion $activate,
        private readonly CmsSettings $settings,
        private readonly ReviewLevels $levels,
        private readonly AssignReviewers $assignments,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $approver, QuestionVersion $version, ConsolidatedPrehoc $input): QuestionVersion
    {
        $this->authorise($approver, $version);

        if (! in_array($version->status, [VersionStatus::Submitted, VersionStatus::UnderReview], true)) {
            throw ValidationException::withMessages(['status' => 'Only a question that is submitted for review can be approved.']);
        }

        $reviews = $this->reviews($version);
        $problem = $this->gate($version, $reviews, $input, reviewsNeeded: false);
        if ($problem !== null) {
            throw ValidationException::withMessages($problem);
        }

        $decision = $this->decision($input->decisionId);
        if (! $decision->is_accept) {
            throw ValidationException::withMessages(['decision_id' => 'A question is approved with Accept or Retain in QBank. Choose Review, Revise or Remove / Discard to do something else with it.']);
        }

        return $this->store($approver, $version, $input, $decision, false);
    }

    /**
     * KMU's shorter path (kmu-cms setting "A question the reviewer accepts goes straight into the
     * QBank"): when the QBank / academic review is in and every review of this round chose an accept
     * decision (Accept, Retain in QBank), the question is stored without waiting for the approving
     * authority. The same gate applies as for an approver: every review needed is in, none asked for
     * changes, and no required checklist item failed. Otherwise nothing happens here and the question
     * waits in the approval queue, as before.
     *
     * The values the question keeps are the last reviewer's (the QBank / academic one), falling back
     * to an earlier reviewer's and then to the author's. The reviewer is recorded as having approved.
     */
    public function acceptedByReviewers(User $reviewer, QuestionVersion $version): ?QuestionVersion
    {
        if (! $this->settings->reviewerAcceptStores()
            || $version->status !== VersionStatus::UnderReview
            || $version->author_id === $reviewer->id) {
            return null;
        }

        $reviews = $this->reviews($version);
        if ($this->gate($version, $reviews) !== null) {
            return null;
        }

        $reviewed = array_values(array_filter($reviews, fn (Review $review): bool => ! $review->requestedChanges()));
        $decisions = PrehocDecision::query()
            ->whereIn('id', array_map(fn (Review $review): int => (int) $review->decision_id, $reviewed))
            ->get()->keyBy('id');
        foreach ($reviewed as $review) {
            $decision = $decisions->get((int) $review->decision_id);
            if (! $decision instanceof PrehocDecision || ! $decision->is_accept) {
                return null;
            }
        }

        // The last review (the QBank / academic one, or the department / subject one when that is the
        // only level) carries the question's decision.
        $last = $reviewed[count($reviewed) - 1];
        $prehoc = PrehocAssessment::query()
            ->where('version_id', $version->id)
            ->whereIn('review_id', array_map(fn (Review $review): int => $review->id, $reviewed))
            ->orderByDesc('id')
            ->first();

        $input = new ConsolidatedPrehoc(
            decisionId: (int) $last->decision_id,
            cognitiveLevelId: $prehoc?->cognitive_level_id ?? $version->cognitive_level_id,
            difficultyLevelId: $prehoc?->difficulty_level_id ?? $version->difficulty_level_id,
            estimatedP: $prehoc?->estimated_p === null ? null : (float) $prehoc->estimated_p,
            reason: 'Every reviewer accepted it, so it was stored in the QBank without a separate approval (kmu-cms setting).',
        );

        return $this->store($reviewer, $version, $input, $decisions->get((int) $last->decision_id), true);
    }

    private function store(User $approver, QuestionVersion $version, ConsolidatedPrehoc $input, PrehocDecision $decision, bool $byReviewers): QuestionVersion
    {
        return DB::transaction(function () use ($approver, $version, $input, $decision, $byReviewers): QuestionVersion {
            PrehocAssessment::query()->create([
                'version_id' => $version->id,
                'question_id' => $version->question_id,
                'branch_id' => $version->branch_id,
                'review_id' => null,
                'source' => 'consolidated',
                'cognitive_level_id' => $input->cognitiveLevelId,
                'difficulty_level_id' => $input->difficultyLevelId,
                'estimated_p' => $input->estimatedP,
                'decision_id' => $decision->id,
                'reason' => $input->reason,
                'is_consolidated' => true,
                'assessed_by' => $approver->id,
                'assessed_at' => now(),
            ]);

            // The approver may decide before anybody reviewed it: the workflow still passes through
            // under-review, which is the only step an approval may follow.
            if ($version->status === VersionStatus::Submitted) {
                $version->update(['status' => VersionStatus::UnderReview, 'updated_by' => $approver->id]);
                VersionStatusLog::query()->create([
                    'version_id' => $version->id,
                    'from_status' => VersionStatus::Submitted,
                    'to_status' => VersionStatus::UnderReview,
                    'actor_id' => $approver->id,
                    'reason' => null,
                    'occurred_at' => now(),
                ]);
            }

            $from = $version->status;
            $version->update([
                'status' => VersionStatus::Approved,
                'decision_code' => $decision->code,
                'cognitive_level_id' => $input->cognitiveLevelId ?? $version->cognitive_level_id,
                'difficulty_level_id' => $input->difficultyLevelId ?? $version->difficulty_level_id,
                'approved_at' => now(),
                'approved_by' => $approver->id,
                'updated_by' => $approver->id,
            ]);

            VersionStatusLog::query()->create([
                'version_id' => $version->id,
                'from_status' => $from,
                'to_status' => VersionStatus::Approved,
                'actor_id' => $approver->id,
                'reason' => $input->reason,
                'occurred_at' => now(),
            ]);

            $this->audit->record('qbank.question.approved', 'question_version', $version->id, ['status' => $from->value], [
                'status' => VersionStatus::Approved->value,
                'decision' => $decision->code,
                'cognitive_level_id' => $input->cognitiveLevelId,
                'difficulty_level_id' => $input->difficultyLevelId,
                'estimated_p' => $input->estimatedP,
                'by' => $byReviewers ? 'reviewers' : 'approver',
            ], $input->reason, $approver, $version->branch_id);

            // Anyone still asked to review it is no longer needed.
            $this->assignments->cancelOpenFor($version, $approver, 'The question was stored in the QBank.');

            $fresh = $version->fresh() ?? $version;

            // Both callers have already decided this question may be approved, so putting it into
            // use needs no second permission check.
            return $this->settings->autoActivate()
                ? $this->activate->putIntoUse($approver, $fresh, 'Approved questions go into use straight away (kmu-cms setting).')
                : $fresh;
        });
    }

    /**
     * Why this version cannot be approved yet, for the approver's screen. Null means it can.
     * The approving authority does not wait for the reviews ($reviewsNeeded false); the reviewers'
     * own shorter path (acceptedByReviewers) does.
     *
     * @param  list<Review>  $reviews
     * @return array<string, string>|null
     */
    public function gate(QuestionVersion $version, array $reviews, ?ConsolidatedPrehoc $input = null, bool $reviewsNeeded = true): ?array
    {
        $reviewed = array_values(array_filter($reviews, fn (Review $review): bool => ! $review->requestedChanges()));
        $required = $this->settings->reviewsRequired();
        $subject = count(array_filter($reviewed, fn (Review $review): bool => $this->levels->counts($review, ReviewStage::Subject)));
        $academic = count(array_filter($reviewed, fn (Review $review): bool => $this->levels->counts($review, ReviewStage::Academic)));

        if ($reviewsNeeded && $subject < $required) {
            return ['reviews' => $this->levels->single()
                ? 'This question needs '.$required.' review(s) and has '.$subject.'.'
                : 'This question needs '.$required.' department / subject review(s) and has '.$subject.'.'];
        }

        $failed = [];
        foreach ($reviewed as $review) {
            foreach ($this->checklist->failedRequired($review->checklist ?? []) as $text) {
                $failed[$text] = $text;
            }
        }
        if ($failed !== []) {
            return ['checklist' => 'A reviewer marked a required checklist item as failed: '.implode('; ', array_values($failed)).'. Send it back to the author.'];
        }

        if ($reviewsNeeded && $academic < ($this->levels->single() ? 0 : 1)) {
            return ['reviews' => 'This question is waiting for its QBank / academic review.'];
        }

        if ($input !== null && $this->reviewersDisagree($reviewed) && ($input->reason === null || mb_strlen(trim($input->reason)) < 10)) {
            return ['reason' => 'The reviewers decided differently, so say why you settled on this (at least 10 characters).'];
        }

        return null;
    }

    /**
     * The reviews that count: those submitted since the author last sent the question for review.
     * A review from before the author changed it is history, not a vote on what is there now.
     *
     * @return list<Review>
     */
    public function reviews(QuestionVersion $version): array
    {
        return array_values(Review::query()
            ->where('version_id', $version->id)
            ->where('round', $version->review_round)
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * @param  iterable<int, Review>  $reviews
     * @return list<Review>
     */
    public function reviewsOfRound(QuestionVersion $version, iterable $reviews): array
    {
        $current = [];
        foreach ($reviews as $review) {
            if ($review->round === $version->review_round) {
                $current[] = $review;
            }
        }

        return $current;
    }

    /**
     * @param  list<Review>  $reviewed
     */
    private function reviewersDisagree(array $reviewed): bool
    {
        $decisions = array_unique(array_map(fn (Review $review): int => (int) $review->decision_id, $reviewed));

        return count($decisions) > 1;
    }

    private function decision(int $decisionId): PrehocDecision
    {
        $decision = PrehocDecision::query()->where('is_active', true)->find($decisionId);

        if (! $decision instanceof PrehocDecision) {
            throw ValidationException::withMessages(['decision_id' => 'Choose the decision for this question.']);
        }

        return $decision;
    }

    private function authorise(User $approver, QuestionVersion $version): void
    {
        $target = new ScopeTarget($version->branch_id, $version->programme_id, $version->professional_id, $version->course_id);

        if (! $this->access->allows($approver, 'qbank.question.approve', $target)) {
            throw new AuthorizationException('You cannot approve questions of this course.');
        }
        if ($version->author_id === $approver->id) {
            throw new AuthorizationException('You cannot approve your own question.');
        }
    }
}
