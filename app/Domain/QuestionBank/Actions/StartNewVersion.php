<?php

namespace App\Domain\QuestionBank\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Validation\VersionContentReader;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Editing a question that is already approved or in use does not change it: it starts the next
 * version as a draft, copied from the one in use. The version in use stays active until the new one
 * is approved (blueprint 8.2).
 */
final class StartNewVersion
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly VersionContentReader $reader,
        private readonly WriteVersionContent $writeContent,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $user, Question $question): QuestionVersion
    {
        /** @var QuestionVersion|null $latest */
        $latest = $question->versions()->orderByDesc('version_no')->first();
        if ($latest === null) {
            throw ValidationException::withMessages(['question' => 'This question has no version to copy.']);
        }
        if ($latest->status->isEditable()) {
            throw ValidationException::withMessages(['question' => 'This question already has a draft (version '.$latest->version_no.'). Edit that one.']);
        }

        $source = $question->activeVersion ?? $latest;
        $permission = (int) $source->author_id === $user->id ? 'qbank.question.edit_own' : 'qbank.question.edit_any';
        if (! $this->access->allows($user, $permission, new ScopeTarget($source->branch_id, $source->programme_id, $source->professional_id, $source->course_id))) {
            throw new AuthorizationException('You cannot edit this question.');
        }

        $content = $this->reader->read($source);

        return DB::transaction(function () use ($user, $question, $latest, $source, $content): QuestionVersion {
            $version = QuestionVersion::query()->create([
                'question_id' => $question->id,
                'version_no' => $latest->version_no + 1,
                'question_type_id' => $source->question_type_id,
                'branch_id' => $source->branch_id,
                'vignette' => $source->vignette,
                'stem' => $source->stem,
                'lead_in' => $source->lead_in,
                'explanation' => $source->explanation,
                'settings' => $source->settings,
                'marks' => $source->marks,
                'negative_marks' => $source->negative_marks,
                'programme_id' => $source->programme_id,
                'professional_id' => $source->professional_id,
                'term_id' => $source->term_id,
                'course_id' => $source->course_id,
                'node_id' => $source->node_id,
                'discipline_id' => $source->discipline_id,
                'cognitive_level_id' => $source->cognitive_level_id,
                'difficulty_level_id' => $source->difficulty_level_id,
                'status' => VersionStatus::Draft,
                'content_hash' => $source->content_hash,
                'search_text' => $source->search_text,
                'source' => $source->source,
                'author_id' => $user->id,
                'created_by' => $user->id,
            ]);

            ($this->writeContent)($version, $content);
            $question->update(['latest_version_no' => $version->version_no]);

            $this->audit->record('qbank.version.created', 'question_version', $version->id, null, [
                'question_id' => $question->id,
                'version_no' => $version->version_no,
                'copied_from_version_no' => $source->version_no,
            ], null, $user, $version->branch_id);

            return $version;
        });
    }
}
