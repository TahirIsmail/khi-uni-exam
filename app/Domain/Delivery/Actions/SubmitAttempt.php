<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Marking\Actions\AutoMarkAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes an attempt: by the candidate, by the deadline itself once the grace period is over, or by
 * an invigilator. Submitting ends whatever session is open on it, so nothing more can be answered
 * (the database refuses it too — migration 2026_09_30_000102), and auto-marks every objective item
 * against the sealed key (step 20) — every submission path funnels through here, so nothing else
 * needs to remember to do it.
 */
final class SubmitAttempt
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AutoMarkAttempt $autoMark,
    ) {}

    /** @param  'candidate'|'invigilator'|'auto'  $by */
    public function __invoke(CandidateExam $attempt, string $by): CandidateExam
    {
        return DB::transaction(function () use ($attempt, $by): CandidateExam {
            $attempt = CandidateExam::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($attempt->status === AttemptStatus::Submitted) {
                return $attempt;
            }
            if ($attempt->status !== AttemptStatus::InProgress) {
                throw ValidationException::withMessages(['attempt' => 'This attempt cannot be submitted right now.']);
            }

            $attempt->update(['status' => AttemptStatus::Submitted, 'submitted_at' => now(), 'submitted_by' => $by]);

            DB::table('dlv_submissions')->insert([
                'candidate_exam_id' => $attempt->id,
                'submitted_at' => now(),
                'submitted_by' => $by,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('dlv_sessions')->where('candidate_exam_id', $attempt->id)->whereNull('ended_at')
                ->update(['ended_at' => now(), 'end_reason' => 'submitted']);

            $this->audit->record('candidate.exam_submitted', 'candidate_exam', $attempt->id, null, ['submitted_by' => $by], null, null, $attempt->examination->branch_id);

            $this->autoMark->__invoke($attempt);

            return $attempt;
        });
    }
}
