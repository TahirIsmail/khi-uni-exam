<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Models\CandidateExam;

/**
 * The deadline is enforced here, checked on every heartbeat and every answer: there is no separate
 * scheduled job for it, so an attempt no browser ever calls back into (the candidate simply walked
 * away) is still closed the next time anything touches it. `php artisan exam:close-expired-attempts`
 * is the safety net for one that nobody calls back into at all.
 */
final class EnforceDeadline
{
    public function __construct(private readonly SubmitAttempt $submit) {}

    public function __invoke(CandidateExam $attempt): CandidateExam
    {
        if ($attempt->status !== AttemptStatus::InProgress || $attempt->deadline_at === null) {
            return $attempt;
        }

        $cutoff = $attempt->deadline_at->clone()->addSeconds((int) config('exam.delivery.grace_seconds'));
        if (now()->greaterThan($cutoff)) {
            return $this->submit->__invoke($attempt, 'auto');
        }

        return $attempt;
    }
}
