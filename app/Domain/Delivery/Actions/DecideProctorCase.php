<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\Actions\CandidateGuard;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Enums\ProctorDecisionType;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Delivery\Models\ProctorDecision;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The committee's call against a proctoring case (exam-phase.md, step 19): a decision is recorded
 * whatever it is, and voiding the attempt is the same recorded transition an invigilator's other
 * actions on an attempt already are — never a silent side effect of reviewing.
 */
final class DecideProctorCase
{
    public function __construct(
        private readonly CandidateGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(
        User $user,
        CandidateExam $attempt,
        ProctorDecisionType $decision,
        string $reason,
        ?Carbon $coversFrom,
        ?Carbon $coversTo,
    ): ProctorDecision {
        $this->guard->authorise($user, $attempt->examination, 'proctor.review.decide', 'You cannot decide proctoring cases for this course.');

        return DB::transaction(function () use ($user, $attempt, $decision, $reason, $coversFrom, $coversTo): ProctorDecision {
            $record = ProctorDecision::query()->create([
                'candidate_exam_id' => $attempt->id,
                'covers_from' => $coversFrom,
                'covers_to' => $coversTo,
                'decision' => $decision,
                'reason' => $reason,
                'decided_by' => $user->id,
                'decided_at' => now(),
            ]);

            if ($decision === ProctorDecisionType::VoidAttempt) {
                $attempt->update(['status' => AttemptStatus::Voided]);
            }

            $this->audit->record('proctor.decision_recorded', 'candidate_exam', $attempt->id, null, ['decision' => $decision->value], $reason, $user, $attempt->examination->branch_id);

            return $record;
        });
    }
}
