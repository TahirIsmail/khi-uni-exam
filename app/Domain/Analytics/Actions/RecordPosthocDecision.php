<?php

namespace App\Domain\Analytics\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\Actions\CandidateGuard;
use App\Domain\Exam\Models\Examination;
use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\PosthocDecision;
use App\Domain\QuestionBank\Models\PosthocDecisionType;
use App\Domain\QuestionBank\Models\QuestionUsage;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\VersionStatusLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The decision going back to the question bank, once there are statistics for a question (exam
 * phase, step 22) — KMU's own five words, the same ones a pre-hoc review uses (migration
 * 2026_09_25_000102): accept, retain in QBank, review, revise, or remove/discard. Only the one
 * whose `keeps_question` is false (remove/discard) reaches into the bank itself — the same way an
 * approver already archives a question with no live version left
 * (App\Domain\QuestionBank\Review\RejectVersion). The others are recorded and shown on the
 * question's history; a revise does not itself reopen the version for editing.
 */
final class RecordPosthocDecision
{
    public function __construct(
        private readonly CandidateGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $user, QuestionVersion $version, Examination $examination, string $decisionCode, string $reason): PosthocDecision
    {
        $this->guard->authorise($user, $examination, 'analytics.decision.record', 'You cannot record post-hoc decisions for this course.');

        $usage = QuestionUsage::query()->where('version_id', $version->id)->where('exam_id', $examination->id)->first();
        if ($usage === null) {
            throw ValidationException::withMessages(['analysis' => 'Run analysis for this examination before deciding about a question.']);
        }

        $decisionType = PosthocDecisionType::query()->where('code', $decisionCode)->where('is_active', true)->first();
        if ($decisionType === null) {
            throw ValidationException::withMessages(['decision' => 'That is not a decision this screen offers.']);
        }

        return DB::transaction(function () use ($user, $version, $examination, $decisionType, $usage, $reason): PosthocDecision {
            $decision = PosthocDecision::query()->create([
                'version_id' => $version->id,
                'question_id' => $version->question_id,
                'branch_id' => $examination->branch_id,
                'exam_id' => $examination->id,
                'decision_type_id' => $decisionType->id,
                'observed_p' => $usage->observed_p,
                'discrimination' => $usage->discrimination,
                'candidates' => $usage->candidates,
                'reason' => $reason,
                'decided_by' => $user->id,
                'decided_at' => now(),
            ]);

            $questionArchived = false;
            if (! $decisionType->keeps_question) {
                $questionArchived = $this->discard($version, $user, $reason);
            }

            $this->audit->record(
                'analytics.decision_recorded',
                'question_version',
                $version->id,
                null,
                ['decision' => $decisionType->code, 'question_archived' => $questionArchived],
                $reason,
                $user,
                $examination->branch_id,
            );

            return $decision;
        });
    }

    /** @return bool whether the question itself was also archived */
    private function discard(QuestionVersion $version, User $user, string $reason): bool
    {
        if ($version->status === VersionStatus::Active) {
            $version->update(['status' => VersionStatus::Retired, 'updated_by' => $user->id]);

            VersionStatusLog::query()->create([
                'version_id' => $version->id,
                'from_status' => VersionStatus::Active,
                'to_status' => VersionStatus::Retired,
                'actor_id' => $user->id,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);
        }

        $question = $version->question;
        $live = $question->versions()
            ->whereNotIn('status', [VersionStatus::Archived, VersionStatus::Superseded, VersionStatus::Retired])
            ->exists();

        if (! $live && ! $question->is_archived) {
            $question->update([
                'is_archived' => true,
                'archived_at' => now(),
                'archived_by' => $user->id,
                'archive_reason' => $reason,
            ]);

            return true;
        }

        return false;
    }
}
