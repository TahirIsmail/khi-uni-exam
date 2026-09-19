<?php

namespace App\Domain\QuestionBank\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\PrehocAssessment;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\VersionStatusLog;
use App\Domain\QuestionBank\Review\AssignReviewers;
use App\Domain\QuestionBank\Validation\QuestionValidator;
use App\Domain\QuestionBank\Validation\VersionContentReader;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends a draft for review. Everything the type requires must be there: the same checks the editor
 * shows are applied again here, because the editor cannot be trusted.
 *
 * Submission also starts the review: the author's own level-of-thinking and difficulty are kept as
 * their proposal (a reviewer's values override it later, and both are stored), and reviewers are
 * assigned automatically from the course's pool.
 */
final class SubmitQuestionVersion
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly QuestionValidator $validator,
        private readonly VersionContentReader $reader,
        private readonly AssignReviewers $assignReviewers,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $user, QuestionVersion $version, ?string $note = null): QuestionVersion
    {
        if (! $version->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Only a draft can be sent for review.']);
        }
        if (! $this->access->allows($user, 'qbank.question.submit', new ScopeTarget($version->branch_id, $version->programme_id, $version->professional_id, $version->course_id))) {
            throw new AuthorizationException('You cannot send questions for review.');
        }

        $result = $this->validator->check($this->reader->read($version));
        if ($result['errors'] !== []) {
            throw ValidationException::withMessages($result['errors']);
        }

        return DB::transaction(function () use ($user, $version, $note): QuestionVersion {
            $from = $version->status;
            // Each submission is a new round of review; the earlier round's reviews become history.
            $version->update([
                'status' => VersionStatus::Submitted,
                'submitted_at' => now(),
                'review_round' => $version->review_round + 1,
                'updated_by' => $user->id,
            ]);

            VersionStatusLog::query()->create([
                'version_id' => $version->id,
                'from_status' => $from,
                'to_status' => VersionStatus::Submitted,
                'actor_id' => $user->id,
                'reason' => $note,
                'occurred_at' => now(),
            ]);

            if ($version->cognitive_level_id !== null || $version->difficulty_level_id !== null) {
                PrehocAssessment::query()->create([
                    'version_id' => $version->id,
                    'question_id' => $version->question_id,
                    'branch_id' => $version->branch_id,
                    'review_id' => null,
                    'source' => 'author',
                    'cognitive_level_id' => $version->cognitive_level_id,
                    'difficulty_level_id' => $version->difficulty_level_id,
                    'is_consolidated' => false,
                    'assessed_by' => $user->id,
                    'assessed_at' => now(),
                ]);
            }

            $assigned = $this->assignReviewers->auto($version, $user);

            $this->audit->record('qbank.version.submitted', 'question_version', $version->id, ['status' => $from->value], [
                'status' => VersionStatus::Submitted->value,
                'reviewers_assigned' => count($assigned),
            ], $note, $user, $version->branch_id);

            return $version->fresh() ?? $version;
        });
    }
}
