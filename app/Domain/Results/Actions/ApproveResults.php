<?php

namespace App\Domain\Results\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\Actions\CandidateGuard;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Exam\Models\Examination;
use App\Domain\Results\Enums\PublicationStatus;
use App\Domain\Results\Models\ResultPublication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approving an examination's results, all attempts at once (exam phase, step 21) — the same
 * granularity the blueprint and paper are already approved at. Every submitted attempt is
 * recompiled first, so approval always reflects the latest marking.
 */
final class ApproveResults
{
    public function __construct(
        private readonly CandidateGuard $guard,
        private readonly CompileResult $compile,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $user, Examination $examination): ResultPublication
    {
        $this->guard->authorise($user, $examination, 'result.approve', 'You cannot approve results for this course.');

        $attempts = CandidateExam::query()->where('examination_id', $examination->id)
            ->where('status', AttemptStatus::Submitted)->get();

        if ($attempts->isEmpty()) {
            throw ValidationException::withMessages(['results' => 'Nobody has submitted this examination yet.']);
        }

        foreach ($attempts as $attempt) {
            $result = $this->compile->__invoke($attempt);
            if ($result->pending_items) {
                throw ValidationException::withMessages(['results' => 'Every attempt must be fully marked before results can be approved.']);
            }
        }

        return DB::transaction(function () use ($examination, $user): ResultPublication {
            $publication = ResultPublication::query()->updateOrCreate(
                ['examination_id' => $examination->id],
                ['status' => PublicationStatus::Approved, 'approved_by' => $user->id, 'approved_at' => now()],
            );

            $this->audit->record('result.approved', 'examination', $examination->id, null, null, null, $user, $examination->branch_id);

            return $publication;
        });
    }
}
