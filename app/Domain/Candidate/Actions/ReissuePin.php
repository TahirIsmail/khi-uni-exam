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
 * A fresh PIN for a candidate who has already checked in — lost, forgotten, or given to the wrong
 * person by mistake. The old PIN stops working the moment this runs: only the newest hash is kept.
 */
final class ReissuePin
{
    public function __construct(
        private readonly CandidateGuard $guard,
        private readonly CandidatePin $pins,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{candidate: Candidate, pin: string} */
    public function __invoke(User $user, Examination $examination, Candidate $candidate): array
    {
        $this->guard->authorise($user, $examination, 'candidate.checkin', 'You cannot issue exam PINs for this course.');
        if ($candidate->examination_id !== $examination->id) {
            throw ValidationException::withMessages(['candidate' => 'That candidate is not on this examination.']);
        }
        if ($candidate->status !== CandidateStatus::CheckedIn) {
            throw ValidationException::withMessages(['candidate' => 'This candidate has not checked in yet.']);
        }

        $pin = $this->pins->generate();

        $candidate = DB::transaction(function () use ($user, $examination, $candidate, $pin): Candidate {
            $candidate->update([
                'pin_hash' => $this->pins->hash($pin),
                'pin_issued_by' => $user->id,
                'pin_issued_at' => now(),
                'updated_by' => $user->id,
            ]);

            $this->audit->record('candidate.pin_reissued', 'candidate', $candidate->id, null, ['candidate_no' => $candidate->candidate_no], null, $user, $examination->branch_id);

            return $candidate;
        });

        return ['candidate' => $candidate, 'pin' => $pin];
    }
}
