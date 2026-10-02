<?php

namespace App\Domain\QuestionBank\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\PublicRef;
use App\Domain\QuestionBank\Support\Filing;
use App\Domain\QuestionBank\Validation\QuestionContent;
use App\Models\User;
use App\Support\Cms\CmsAcademic;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a question with its first draft version, in the campus the author is working in and on a
 * topic they may write for. The draft may still be incomplete: it is checked again on submission.
 */
final class CreateQuestionDraft
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly CmsAcademic $academic,
        private readonly Filing $filing,
        private readonly WriteVersionContent $writeContent,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $author, int $branchId, QuestionContent $content, string $source = 'manual', ?int $importRowId = null): QuestionVersion
    {
        $place = $this->filing->place($content);
        if (! $this->academic->programmeInUse($place['programme_id'])) {
            throw ValidationException::withMessages(['course_id' => 'This program is switched off in the CMS, so no new questions are filed under it.']);
        }
        if ($place['branch_id'] !== $branchId) {
            throw ValidationException::withMessages(['course_id' => 'That course belongs to another campus.']);
        }
        if ($content->examTypeId !== null && ! $this->academic->examTypeFits($content->examTypeId, $place['programme_id'])) {
            throw ValidationException::withMessages(['exam_type_id' => 'That examination type is not used by this programme (Annual and Supplementary are for annual programmes, Regular and Retake for semester programmes).']);
        }
        if (! $this->access->allows($author, 'qbank.question.create', new ScopeTarget($branchId, $place['programme_id'], $place['professional_id'], $place['course_id']))) {
            throw new AuthorizationException('You cannot write questions for this course.');
        }

        $intakeId = $this->filing->intake($content->intakeId, $branchId);

        return DB::transaction(function () use ($author, $branchId, $content, $place, $intakeId, $source, $importRowId): QuestionVersion {
            $question = Question::query()->create([
                'public_ref' => PublicRef::next(),
                'branch_id' => $branchId,
                'course_id' => $place['course_id'],
                'latest_version_no' => 1,
                'created_by' => $author->id,
            ]);

            $version = QuestionVersion::query()->create([
                'question_id' => $question->id,
                'version_no' => 1,
                'question_type_id' => $content->type->id,
                'branch_id' => $branchId,
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
                'status' => VersionStatus::Draft,
                'content_hash' => $content->contentHash(),
                'search_text' => $content->searchText(),
                'source' => $source,
                'import_row_id' => $importRowId,
                'author_id' => $author->id,
                'created_by' => $author->id,
            ]);

            ($this->writeContent)($version, $content);

            $question->update(['active_version_id' => null]);

            $this->audit->record('qbank.question.created', 'question', $question->id, null, [
                'public_ref' => $question->public_ref,
                'course_id' => $question->course_id,
                'node_id' => $version->node_id,
                'type' => $content->type->code,
                'source' => $source,
            ], null, $author, $branchId);

            return $version;
        });
    }
}
