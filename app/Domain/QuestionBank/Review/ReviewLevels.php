<?php

namespace App\Domain\QuestionBank\Review;

use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\QuestionBank\Models\Review;
use App\Models\User;
use App\Support\Cms\CmsSettings;

/**
 * How many levels of review a question goes through, and what that means everywhere.
 *
 * KMU (2026-10-03): one reviewer per question — once the designated reviewer accepts, the question
 * is approved and stored (kmu-cms Exam Module Settings: "Questions also need the QBank / Academic
 * review" off, the default). The two-level path (Department / Subject, then QBank / Academic) stays
 * available behind that setting.
 *
 * With one level:
 *  - it is simply called "Review";
 *  - anyone holding either review right for the course may be the reviewer (KMU's Subject
 *    Specialists hold one, DME/DDE reviewers the other);
 *  - every review of the round counts, whatever level it was asked at, so questions already in
 *    review when the setting changed finish as one-level questions.
 */
final class ReviewLevels
{
    public function __construct(
        private readonly CmsSettings $settings,
        private readonly AccessControl $access,
    ) {}

    public function single(): bool
    {
        return ! $this->settings->academicReview();
    }

    public function label(ReviewStage $stage): string
    {
        return $this->single() ? 'Review' : $stage->label();
    }

    /**
     * Whether this user holds the right to review at this level, for this course.
     *
     * KMU (2026-10-06): the approving authority reviews, an author never does. Somebody who writes
     * questions but cannot approve them is no reviewer, whatever review right their role holds; with
     * one level, an approver may review without holding a review right of their own.
     */
    public function mayReview(User $user, ScopeTarget $target, ReviewStage $stage): bool
    {
        $approver = $this->access->allows($user, 'qbank.question.approve', $target);
        if (! $approver && $this->access->allows($user, 'qbank.question.create', $target)) {
            return false;
        }

        if ($this->single()) {
            return $approver
                || $this->access->allows($user, ReviewStage::Subject->permission(), $target)
                || $this->access->allows($user, ReviewStage::Academic->permission(), $target);
        }

        return $this->access->allows($user, $stage->permission(), $target);
    }

    /**
     * Whether a review counts towards a level: with one level every review counts.
     */
    public function counts(Review $review, ReviewStage $stage): bool
    {
        return $this->single() ? $stage === ReviewStage::Subject : $review->stage === $stage->value;
    }
}
