<?php

namespace App\Domain\QuestionBank\Queries;

use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\QuestionBank\Models\CognitiveLevel;
use App\Domain\QuestionBank\Models\DifficultyLevel;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\Tag;
use App\Domain\QuestionBank\Validation\VersionContentReader;
use App\Models\User;
use App\Support\Cms\CmsAcademic;

/**
 * Everything the editor screen needs: the question types with their shape and settings, the places
 * in the academic structure the author may choose, the lookup lists, and a stored version to edit.
 */
final class QuestionEditorData
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly CourseScope $courseScope,
        private readonly CmsAcademic $academic,
        private readonly VersionContentReader $reader,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forCreate(User $user, int $branchId): array
    {
        return [
            'courses' => $this->courses($user, $branchId),
            'tags' => Tag::query()->where('branch_id', $branchId)->orderBy('name')->get(['id', 'name'])->all(),
            ...$this->lookups(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function lookups(): array
    {
        return [
            'types' => QuestionType::query()->where('is_active', true)->orderBy('sort_order')->get()->map(fn (QuestionType $type): array => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'family' => $type->family,
                'description' => $type->description,
                'hasOptions' => $type->has_options,
                'optionsMin' => $type->options_min,
                'optionsMax' => $type->options_max,
                'correctMin' => $type->correct_min,
                'correctMax' => $type->correct_max,
                'hasItems' => $type->has_items,
                'itemsMin' => $type->items_min,
                'itemsMax' => $type->items_max,
                'itemAnswer' => $type->item_answer->value,
                'hasAcceptedAnswers' => $type->has_accepted_answers,
                'hasNumericAnswer' => $type->has_numeric_answer,
                'isManuallyMarked' => $type->is_manually_marked,
                'supportsShuffle' => $type->supports_shuffle,
                'supportsPartialCredit' => $type->supports_partial_credit,
                'supportsNegativeMarks' => $type->supports_negative_marks,
                'supportsRubric' => $type->supports_rubric,
                'defaultSettings' => $type->default_settings,
            ])->all(),
            'cognitiveLevels' => CognitiveLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'description'])->all(),
            'difficultyLevels' => DifficultyLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name'])->all(),
            'limits' => [
                'stemMin' => (int) config('qbank.stem.min_length'),
                'stemMax' => (int) config('qbank.stem.max_length'),
                'marksMax' => (float) config('qbank.marks.max'),
                'referenceRequired' => config('qbank.require_reference') === true,
            ],
        ];
    }

    /**
     * Courses in this campus the author may write for.
     *
     * @return list<array{id: int, code: string, title: string, programme_id: int, professional_id: int|null, term_id: int|null}>
     */
    public function courses(User $user, int $branchId): array
    {
        $allowed = $this->courseScope->courseIds($user, $branchId);
        $courses = $this->academic->courses($branchId);

        return $allowed === null
            ? $courses
            : array_values(array_filter($courses, fn (array $course): bool => in_array($course['id'], $allowed, true)));
    }

    /**
     * The topics of a course, for the taxonomy picker.
     *
     * @return list<array<string, mixed>>
     */
    public function curriculum(int $courseId): array
    {
        return $this->academic->curriculum($courseId);
    }

    /**
     * A stored version in the shape the editor works with.
     *
     * @return array<string, mixed>
     */
    public function version(QuestionVersion $version): array
    {
        $content = $this->reader->read($version);

        return [
            'id' => $version->id,
            'questionId' => $version->question_id,
            'versionNo' => $version->version_no,
            'status' => $version->status->value,
            'statusLabel' => $version->status->label(),
            'editable' => $version->isEditable(),
            'questionTypeId' => $version->question_type_id,
            'courseId' => $version->course_id,
            'nodeId' => $version->node_id,
            'vignette' => $content->vignette,
            'stem' => $content->stem,
            'leadIn' => $content->leadIn,
            'explanation' => $content->explanation,
            'settings' => $content->settings,
            'marks' => $content->marks,
            'negativeMarks' => $content->negativeMarks,
            'cognitiveLevelId' => $content->cognitiveLevelId,
            'difficultyLevelId' => $content->difficultyLevelId,
            'options' => $content->options,
            'items' => $content->items,
            'answers' => $content->answers,
            'rubric' => $content->rubric,
            'references' => $content->references,
            'tagIds' => $content->tagIds,
            'authorId' => $version->author_id,
        ];
    }

    public function allows(User $user, string $permission, QuestionVersion $version): bool
    {
        return $this->access->allows($user, $permission, new ScopeTarget(
            $version->branch_id,
            $version->programme_id,
            $version->professional_id,
            $version->course_id,
        ));
    }
}
