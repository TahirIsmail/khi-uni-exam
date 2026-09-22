<?php

namespace App\Domain\Candidate\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Exam\Models\Examination;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Extra time for a candidate, granted ahead of the exam day and always with a reason: the delivery
 * engine (step 18) adds these minutes to their deadline. Set the minutes to null to take it back.
 */
final class GrantExtraTime
{
    public function __construct(
        private readonly CandidateGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $user, Examination $examination, Candidate $candidate, ?int $minutes, ?string $reason): Candidate
    {
        $this->guard->authorise($user, $examination, 'candidate.extra_time', 'You cannot grant extra time for this course.');
        if ($candidate->examination_id !== $examination->id) {
            throw ValidationException::withMessages(['candidate' => 'That candidate is not on this examination.']);
        }
        if ($minutes !== null && ($minutes < 1 || $minutes > 600)) {
            throw ValidationException::withMessages(['minutes' => 'Extra time must be between 1 and 600 minutes.']);
        }
        if ($minutes !== null && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Say why extra time is granted.']);
        }

        $before = ['extra_time_minutes' => $candidate->extra_time_minutes];

        return DB::transaction(function () use ($user, $examination, $candidate, $minutes, $reason, $before): Candidate {
            $candidate->update([
                'extra_time_minutes' => $minutes,
                'extra_time_reason' => $minutes === null ? null : trim((string) $reason),
                'extra_time_granted_by' => $minutes === null ? null : $user->id,
                'extra_time_granted_at' => $minutes === null ? null : now(),
                'updated_by' => $user->id,
            ]);

            $this->audit->record('candidate.extra_time_granted', 'candidate', $candidate->id, $before, ['extra_time_minutes' => $minutes], $minutes === null ? null : $reason, $user, $examination->branch_id);

            return $candidate;
        });
    }
}
