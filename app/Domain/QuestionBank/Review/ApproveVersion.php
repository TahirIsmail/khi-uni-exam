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
 * The approval gate (blueprint 9.2, KMU QBank mechanism). A version is approved only when:
 *
 *  - as many department / subject reviews are in as kmu-cms asks for, and the QBank / academic
 *    review after them, and none of them asked for changes;
 *  - no required item of the item-writing checklist was marked as failed;
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
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $approver, QuestionVersion $version, ConsolidatedPrehoc $input): QuestionVersion
    {
        $this->authorise($approver, $version);

        if ($version->status !== VersionStatus::UnderReview) {
            throw ValidationException::withMessages(['status' => 'Only a question that has been reviewed can be approved.']);
        }

        $reviews = $this->reviews($version);
        $problem = $this->gate($version, $reviews, $input);
        if ($problem !== null) {
            throw ValidationException::withMessages($problem);
        }

        $decision = $this->decision($input->decisionId);
        if (! $decision->is_accept) {
            throw ValidationException::withMessages(['decision_id' => 'A question can only be approved with an "accept" decision. Send it back to the author, or archive it, instead.']);
        }

        return DB::transaction(function () use ($approver, $version, $input, $decision): QuestionVersion {
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

            $from = $version->status;
            $version->update([
                'status' => VersionStatus::Approved,
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
            ], $input->reason, $approver, $version->branch_id);

            $fresh = $version->fresh() ?? $version;

            return $this->settings->autoActivate() ? ($this->activate)($approver, $fresh, 'Approved questions go into use straight away (kmu-cms setting).') : $fresh;
        });
    }

    /**
     * Why this version cannot be approved yet, for the approver's screen. Null means it can.
     *
     * @param  list<Review>  $reviews
     * @return array<string, string>|null
     */
    public function gate(QuestionVersion $version, array $reviews, ?ConsolidatedPrehoc $input = null): ?array
    {
        $reviewed = array_values(array_filter($reviews, fn (Review $review): bool => ! $review->requestedChanges()));
        $required = $this->settings->reviewsRequired();
        $subject = count(array_filter($reviewed, fn (Review $review): bool => $review->stage === ReviewStage::Subject->value));
        $academic = count(array_filter($reviewed, fn (Review $review): bool => $review->stage === ReviewStage::Academic->value));

        if ($subject < $required) {
            return ['reviews' => 'This question needs '.$required.' department / subject review(s) and has '.$subject.'.'];
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

        if ($academic < 1) {
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
