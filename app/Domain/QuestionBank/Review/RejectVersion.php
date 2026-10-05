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
 * Remove / Discard: the version is archived with a reason, and the open reviews are called off.
 * An approver may remove a question at any step, even once it is in the QBank; an author may discard
 * their own draft. Nothing is deleted — the question, its versions and the reviews stay readable, so
 * the reason a question was never used can always be found.
 */
final class RejectVersion
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly AssignReviewers $assignments,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $approver, QuestionVersion $version, string $reason): QuestionVersion
    {
        $target = new ScopeTarget($version->branch_id, $version->programme_id, $version->professional_id, $version->course_id);
        $editRight = $version->author_id === $approver->id ? 'qbank.question.edit_own' : 'qbank.question.edit_any';
        $mayDiscardDraft = $version->isEditable() && $this->access->allows($approver, $editRight, $target);
        if (! $mayDiscardDraft && ! $this->access->allows($approver, 'qbank.question.approve', $target)) {
            throw new AuthorizationException('You cannot remove questions of this course.');
        }
        if (! $version->status->canMoveTo(VersionStatus::Archived)) {
            throw ValidationException::withMessages(['status' => 'This question has already been removed or replaced.']);
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => 'Say why this question cannot be used (at least 10 characters).']);
        }

        return DB::transaction(function () use ($approver, $version, $reason): QuestionVersion {
            $this->assignments->cancelOpenFor($version, $approver, 'The question was turned down.');

            $from = $version->status;
            $question = $version->question;
            if ($question->active_version_id === $version->id) {
                $question->update(['active_version_id' => null]);
            }
            $version->update(['status' => VersionStatus::Archived, 'decision_code' => 'remove', 'updated_by' => $approver->id]);

            VersionStatusLog::query()->create([
                'version_id' => $version->id,
                'from_status' => $from,
                'to_status' => VersionStatus::Archived,
                'actor_id' => $approver->id,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);

            // A question nobody can use any more is archived as well, so it stays out of searches.
            $live = $question->versions()
                ->whereNotIn('status', [VersionStatus::Archived, VersionStatus::Superseded, VersionStatus::Retired])
                ->where('id', '!=', $version->id)
                ->exists();

            if (! $live && ! $question->is_archived) {
                $question->update([
                    'is_archived' => true,
                    'archived_at' => now(),
                    'archived_by' => $approver->id,
                    'archive_reason' => $reason,
                ]);
            }

            $this->audit->record('qbank.question.rejected', 'question_version', $version->id, ['status' => $from->value], [
                'status' => VersionStatus::Archived->value,
                'question_archived' => ! $live,
            ], $reason, $approver, $version->branch_id);

            return $version->fresh() ?? $version;
        });
    }
}
