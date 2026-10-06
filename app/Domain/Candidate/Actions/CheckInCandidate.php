<?php

namespace App\Domain\Candidate\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\CandidatePin;
use App\Domain\Candidate\Enums\CandidateStatus;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Exam\Models\Examination;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Checks a candidate in on the exam day and issues their one-time PIN: with their candidate number,
 * what ADR-0003 lets them sign in and resume their exam with. The PIN is shown to the invigilator
 * exactly once, in the response; only its hash is kept. An examination with one exam PIN for
 * everyone issues none: checking in only records that the candidate is there, seated or not.
 */
final class CheckInCandidate
{
    public function __construct(
        private readonly CandidateGuard $guard,
        private readonly CandidatePin $pins,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{candidate: Candidate, pin: string|null} */
    public function __invoke(User $user, Examination $examination, Candidate $candidate): array
    {
        $this->guard->authorise($user, $examination, 'candidate.checkin', 'You cannot check candidates in for this course.');
        if ($candidate->examination_id !== $examination->id) {
            throw ValidationException::withMessages(['candidate' => 'That candidate is not on this examination.']);
        }
        $shared = $examination->shared_pin !== null;
        if ($candidate->status === CandidateStatus::Enrolled && ! $shared) {
            throw ValidationException::withMessages(['candidate' => 'This candidate has not been allocated a seat yet.']);
        }

        $pin = $shared ? null : $this->pins->generate();

        $candidate = DB::transaction(function () use ($user, $examination, $candidate, $pin): Candidate {
            $candidate->update([
                'status' => CandidateStatus::CheckedIn->value,
                'checked_in_by' => $user->id,
                'checked_in_at' => now(),
                'pin_hash' => $pin === null ? null : $this->pins->hash($pin),
                'pin_issued_by' => $pin === null ? null : $user->id,
                'pin_issued_at' => $pin === null ? null : now(),
                'updated_by' => $user->id,
            ]);

            $this->audit->record('candidate.checked_in', 'candidate', $candidate->id, null, ['candidate_no' => $candidate->candidate_no], null, $user, $examination->branch_id);

            return $candidate;
        });

        return ['candidate' => $candidate, 'pin' => $pin];
    }
}
