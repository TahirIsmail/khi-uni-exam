<?php

namespace App\Domain\QuestionBank\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Support\Filing;
use App\Domain\QuestionBank\Validation\QuestionContent;
use App\Models\User;
use App\Support\Cms\CmsAcademic;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a draft: the whole question is replaced with what the editor sent. Authors may save their
 * own drafts (qbank.question.edit_own); editing someone else's needs qbank.question.edit_any.
 */
final class SaveQuestionDraft
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly CmsAcademic $academic,
        private readonly Filing $filing,
        private readonly WriteVersionContent $writeContent,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $editor, QuestionVersion $version, QuestionContent $content): QuestionVersion
    {
        if (! $version->isEditable()) {
            throw ValidationException::withMessages(['status' => 'This version is no longer a draft. Create a new version to change the question.']);
        }

        $place = $this->filing->place($content);
        if ($place['branch_id'] !== $version->branch_id) {
            throw ValidationException::withMessages(['course_id' => 'A question cannot be moved to another campus.']);
        }
        if ($content->examTypeId !== null && ! $this->academic->examTypeFits($content->examTypeId, $place['programme_id'])) {
            throw ValidationException::withMessages(['exam_type_id' => 'That examination type is not used by this programme (Annual and Supplementary are for annual programmes, Regular and Retake for semester programmes).']);
        }

        $permission = (int) $version->author_id === $editor->id ? 'qbank.question.edit_own' : 'qbank.question.edit_any';
        if (! $this->access->allows($editor, $permission, new ScopeTarget($version->branch_id, $place['programme_id'], $place['professional_id'], $place['course_id']))) {
            throw new AuthorizationException('You cannot edit this question.');
        }

        $intakeId = $this->filing->intake($content->intakeId, $version->branch_id, $version->intake_id);

        return DB::transaction(function () use ($editor, $version, $content, $place, $intakeId): QuestionVersion {
            $before = [
                'stem' => $version->stem,
                'marks' => $version->marks,
                'node_id' => $version->node_id,
                'type' => $version->question_type_id,
            ];

            $version->update([
                'question_type_id' => $content->type->id,
                'vignette' => $content->vignette,
                'stem' => $content->stem,
                'lead_in' => $content->leadIn,
                'explanation' => $content->explanation,
                'settings' => $content->settings,
                'marks' => $content->marks,
                'negative_marks' => $content->negativeMarks,
                'programme_id' => $place['programme_id'],
                'professional_id' => $place['professional_id'],
                'term_id' => $place['term_id'],
                'course_id' => $place['course_id'],
                'node_id' => $place['node_id'],
                'discipline_id' => $content->disciplineId ?? $place['discipline_id'],
                'cognitive_level_id' => $content->cognitiveLevelId,
                'difficulty_level_id' => $content->difficultyLevelId,
                'exam_type_id' => $content->examTypeId,
                'intake_id' => $intakeId,
                'content_hash' => $content->contentHash(),
                'search_text' => $content->searchText(),
                'updated_by' => $editor->id,
            ]);

            ($this->writeContent)($version, $content);

            if ((int) $version->course_id !== (int) $version->question->course_id) {
                $version->question->update(['course_id' => $version->course_id]);
            }

            $this->audit->record('qbank.version.draft_saved', 'question_version', $version->id, $before, [
                'stem' => $version->stem,
                'marks' => $version->marks,
                'node_id' => $version->node_id,
                'type' => $version->question_type_id,
            ], null, $editor, $version->branch_id);

            return $version->fresh() ?? $version;
        });
    }
}
