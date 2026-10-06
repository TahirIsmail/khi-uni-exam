<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\Actions\CandidateGuard;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Models\CandidateExam;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Extra minutes added mid-exam, with a reason — a single candidate's individual outage, not a
 * room-wide one (which pauses the room instead). ADR-0003 keeps this separate from the extra time
 * granted before the day (step 17): that one is planned in advance; this one is a correction.
 */
final class GrantCompensatingTime
{
    public function __construct(
        private readonly CandidateGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $user, CandidateExam $attempt, int $minutes, string $reason): CandidateExam
    {
        $this->guard->authorise($user, $attempt->examination, 'delivery.session_control', 'You cannot add time for this course.');

        if ($minutes < 1 || $minutes > 180) {
            throw ValidationException::withMessages(['minutes' => 'Compensating time must be between 1 and 180 minutes.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Say why this time is being added.']);
        }
        if (! in_array($attempt->status, [AttemptStatus::InProgress, AttemptStatus::Paused], true)) {
            throw ValidationException::withMessages(['attempt' => 'This attempt is not in progress.']);
        }

        $seconds = $minutes * 60;
        $attempt->update([
            'deadline_at' => $attempt->deadline_at?->addSeconds($seconds),
            'extra_seconds' => $attempt->extra_seconds + $seconds,
        ]);

        $this->audit->record('candidate.compensating_time_granted', 'candidate_exam', $attempt->id, null, ['minutes' => $minutes], $reason, $user, $attempt->examination->branch_id);

        return $attempt;
    }
}
