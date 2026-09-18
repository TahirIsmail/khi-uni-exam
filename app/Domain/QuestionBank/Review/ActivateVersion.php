<?php

namespace App\Domain\QuestionBank\Review;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\VersionStatusLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts an approved version into use, which is the only status an exam may draw from. The version
 * that was in use before becomes superseded, so a question always has exactly one active version
 * (the database enforces that too).
 */
final class ActivateVersion
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $actor, QuestionVersion $version, ?string $note = null): QuestionVersion
    {
        $target = new ScopeTarget($version->branch_id, $version->programme_id, $version->professional_id, $version->course_id);
        if (! $this->access->allows($actor, 'qbank.question.approve', $target)) {
            throw new AuthorizationException('You cannot put questions into use.');
        }
        if (! in_array($version->status, [VersionStatus::Approved, VersionStatus::OnHold], true)) {
            throw ValidationException::withMessages(['status' => 'Only an approved question can be put into use.']);
        }

        return DB::transaction(function () use ($actor, $version, $note): QuestionVersion {
            $question = $version->question;

            foreach ($question->versions()->where('status', VersionStatus::Active)->where('id', '!=', $version->id)->get() as $previous) {
                $this->move($previous, VersionStatus::Superseded, $actor, 'Version '.$version->version_no.' took its place.');
            }

            $question->update(['active_version_id' => null]);
            $this->move($version, VersionStatus::Active, $actor, $note);
            $version->update(['activated_at' => now()]);
            $question->update(['active_version_id' => $version->id]);

            $this->audit->record('qbank.question.activated', 'question_version', $version->id, ['status' => VersionStatus::Approved->value], [
                'status' => VersionStatus::Active->value,
                'question_id' => $question->id,
            ], $note, $actor, $version->branch_id);

            return $version->fresh() ?? $version;
        });
    }

    private function move(QuestionVersion $version, VersionStatus $to, User $actor, ?string $reason): void
    {
        $from = $version->status;
        $version->update(['status' => $to, 'updated_by' => $actor->id]);

        VersionStatusLog::query()->create([
            'version_id' => $version->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor->id,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }
}
