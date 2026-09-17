<?php

namespace App\Domain\QuestionBank\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\VersionStatusLog;
use App\Domain\QuestionBank\Validation\QuestionValidator;
use App\Domain\QuestionBank\Validation\VersionContentReader;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends a draft for review. Everything the type requires must be there: the same checks the editor
 * shows are applied again here, because the editor cannot be trusted.
 */
final class SubmitQuestionVersion
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly QuestionValidator $validator,
        private readonly VersionContentReader $reader,
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
            $version->update(['status' => VersionStatus::Submitted, 'submitted_at' => now(), 'updated_by' => $user->id]);

            VersionStatusLog::query()->create([
                'version_id' => $version->id,
                'from_status' => $from,
                'to_status' => VersionStatus::Submitted,
                'actor_id' => $user->id,
                'reason' => $note,
                'occurred_at' => now(),
            ]);

            $this->audit->record('qbank.version.submitted', 'question_version', $version->id, ['status' => $from->value], ['status' => VersionStatus::Submitted->value], $note, $user, $version->branch_id);

            return $version->fresh() ?? $version;
        });
    }
}
