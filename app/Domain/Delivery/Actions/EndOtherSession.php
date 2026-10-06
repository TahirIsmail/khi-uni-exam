<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\Actions\CandidateGuard;
use App\Domain\Delivery\Models\CandidateExam;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The invigilator's approval for a candidate to resume on a different computer while their first one
 * is still sending heartbeats (ADR-0003): ending the open session directly, rather than a separate
 * request-and-approve flow — the candidate's very next sign-in then finds nothing in its way.
 */
final class EndOtherSession
{
    public function __construct(
        private readonly CandidateGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $user, CandidateExam $attempt): void
    {
        $this->guard->authorise($user, $attempt->examination, 'delivery.session_control', 'You cannot manage exam sessions for this course.');

        $ended = DB::table('dlv_sessions')->where('candidate_exam_id', $attempt->id)->whereNull('ended_at')
            ->update(['ended_at' => now(), 'end_reason' => 'revoked']);

        if ($ended === 0) {
            throw ValidationException::withMessages(['session' => 'This candidate has no open session to end.']);
        }

        $this->audit->record('candidate.session_ended_by_invigilator', 'candidate_exam', $attempt->id, null, null, null, $user, $attempt->examination->branch_id);
    }
}
