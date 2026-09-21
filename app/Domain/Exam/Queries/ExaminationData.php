<?php

namespace App\Domain\Exam\Queries;

use App\Domain\Blueprint\BlueprintAvailability;
use App\Domain\Blueprint\BlueprintChecker;
use App\Domain\Blueprint\Enums\BlueprintStatus;
use App\Domain\Blueprint\Models\Blueprint;
use App\Domain\Exam\Models\Examination;
use App\Domain\Exam\Models\Section;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\QuestionBank\Models\CognitiveLevel;
use App\Domain\QuestionBank\Models\DifficultyLevel;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Queries\CourseScope;
use App\Models\User;
use App\Support\Cms\CmsAcademic;

/**
 * Everything the examination screens need: what a person may choose when setting one up, a stored
 * examination in the shape the screens work with, what the user may do with it, and its blueprint
 * read against the question bank.
 */
final class ExaminationData
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly CourseScope $courseScope,
        private readonly CmsAcademic $academic,
        private readonly BlueprintChecker $checker,
        private readonly BlueprintAvailability $availability,
    ) {}

    /**
     * The places in the academic structure a person may set an examination for, in KMU's order:
     * programme, then year or semester, then examination, then the Course ID.
     *
     * @return array<string, mixed>
     */
    public function choices(User $user, int $branchId): array
    {
        // Only courses that sit in a year: an examination is named after it.
        $courses = array_values(array_filter(
            $this->courses($user, $branchId),
            fn (array $course): bool => $course['professional_id'] !== null,
        ));
        $programmeIds = array_values(array_unique(array_column($courses, 'programme_id')));

        return [
            'programmes' => array_values(array_filter(
                $this->academic->programmes($branchId),
                fn (array $programme): bool => in_array($programme['id'], $programmeIds, true),
            )),
            'years' => $this->academic->yearsWithCourses($branchId, $courses),
            'programmeCalendars' => $this->academic->calendars($branchId),
            'courses' => $courses,
            'examTypes' => $this->academic->examTypes(),
            'intakes' => $this->academic->intakes($branchId),
            'defaults' => [
                'durationMinutes' => 180,
                'passPercentage' => (float) config('exam.pass_percentage_default'),
            ],
            'limits' => [
                'durationMin' => (int) config('exam.duration_minutes.min'),
                'durationMax' => (int) config('exam.duration_minutes.max'),
                'marksMax' => (float) config('exam.total_marks.max'),
            ],
            'timezone' => (string) config('exam.timezone'),
        ];
    }

    /**
     * Courses of this campus the user may work in (their exam access limits apply).
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
     * An examination in the shape the form and the workspace work with.
     *
     * @return array<string, mixed>
     */
    public function detail(Examination $examination): array
    {
        $examination->loadMissing('createdBy');
        $zone = (string) config('exam.timezone');
        $local = $examination->starts_at?->setTimezone($zone);
        $programme = collect($this->academic->programmes($examination->branch_id))->firstWhere('id', $examination->programme_id);
        $type = collect($this->academic->examTypes())->firstWhere('id', $examination->exam_type_id);
        $intake = collect($this->academic->intakes($examination->branch_id))->firstWhere('id', $examination->intake_id);

        return [
            'id' => $examination->id,
            'reference' => $examination->public_ref,
            'title' => $examination->title,
            'programmeId' => $examination->programme_id,
            'programme' => (string) ($programme['name'] ?? ''),
            'professionalId' => $examination->professional_id,
            'termId' => $examination->term_id,
            'year' => $this->academic->yearName($examination->branch_id, $examination->professional_id, $examination->term_id) ?? '',
            'courseId' => $examination->course_id,
            'course' => (string) $this->academic->courseLabel($examination->course_id),
            'examTypeId' => $examination->exam_type_id,
            'examType' => (string) ($type['name'] ?? ''),
            'intakeId' => $examination->intake_id,
            'intake' => $intake['name'] ?? null,
            'startsAt' => $local?->format('Y-m-d\TH:i'),
            'startsAtLabel' => $local?->format('D j M Y, H:i'),
            'durationMinutes' => $examination->duration_minutes,
            'totalMarks' => $examination->total_marks,
            'passPercentage' => $examination->pass_percentage,
            'passMarks' => round($examination->total_marks * $examination->pass_percentage / 100, 2),
            'negativeMarking' => $examination->negative_marking,
            'negativeFraction' => $examination->negative_fraction,
            'instructions' => $examination->instructions,
            'status' => $examination->status->value,
            'statusLabel' => $examination->status->label(),
            'createdBy' => $examination->createdBy?->name,
        ];
    }

    /**
     * What this user may do with this examination, so the screens offer only that — every action
     * checks the same rights again. Approving is never offered to the person who wrote or submitted
     * the blueprint.
     *
     * @return array{edit: bool, editBlueprint: bool, submit: bool, approve: bool, sendBack: bool, reopen: bool}
     */
    public function abilities(User $user, Examination $examination, Blueprint $blueprint): array
    {
        $target = new ScopeTarget($examination->branch_id, $examination->programme_id, $examination->professional_id, $examination->course_id);
        $manage = $this->access->allows($user, 'exam.blueprint.manage', $target);
        $approve = $this->access->allows($user, 'exam.blueprint.approve', $target);
        $draft = $blueprint->status === BlueprintStatus::Draft;
        $mine = $blueprint->created_by === $user->id || $blueprint->submitted_by === $user->id;

        return [
            'edit' => $this->access->allows($user, 'exam.create', $target),
            'editBlueprint' => $manage && $draft,
            'submit' => $manage && $draft,
            'approve' => $approve && $blueprint->status === BlueprintStatus::Submitted && ! $mine,
            'sendBack' => $approve && $blueprint->status === BlueprintStatus::Submitted,
            'reopen' => $approve && $blueprint->status === BlueprintStatus::Approved,
        ];
    }

    /**
     * The blueprint as the screens show it, with its totals and findings and, for the editor, the
     * topics of the course and what the question bank holds for each.
     *
     * @return array<string, mixed>
     */
    public function blueprint(Examination $examination, Blueprint $blueprint, bool $forEditing): array
    {
        $blueprint->loadMissing(['rows', 'targets']);
        $sections = $examination->sections()->get();
        $sectionIndex = [];
        foreach ($sections as $index => $section) {
            $sectionIndex[$section->id] = $index;
        }

        $names = User::query()->whereIn('id', array_filter([$blueprint->created_by, $blueprint->submitted_by, $blueprint->approved_by]))->pluck('name', 'id');

        $data = [
            'id' => $blueprint->id,
            'status' => $blueprint->status->value,
            'statusLabel' => $blueprint->status->label(),
            'returnReason' => $blueprint->return_reason,
            'createdBy' => $names[$blueprint->created_by] ?? null,
            'submittedBy' => $blueprint->submitted_by === null ? null : ($names[$blueprint->submitted_by] ?? null),
            'submittedAt' => $blueprint->submitted_at?->toIso8601String(),
            'approvedBy' => $blueprint->approved_by === null ? null : ($names[$blueprint->approved_by] ?? null),
            'approvedAt' => $blueprint->approved_at?->toIso8601String(),
            'fingerprint' => $blueprint->approved_hash === null ? null : substr($blueprint->approved_hash, 0, 12),
            'sections' => $sections->map(fn (Section $section): string => $section->name)->values()->all(),
            'rows' => $blueprint->rows->map(fn ($row): array => [
                'section' => $row->section_id === null ? null : ($sectionIndex[$row->section_id] ?? null),
                'node_id' => $row->node_id,
                'question_type_id' => $row->question_type_id,
                'question_count' => $row->question_count,
                'marks_each' => $row->marks_each,
            ])->values()->all(),
            'cognitive' => $blueprint->targets->where('dimension', 'cognitive')->map(fn ($target): array => ['level_id' => $target->level_id, 'percent' => $target->percent])->values()->all(),
            'difficulty' => $blueprint->targets->where('dimension', 'difficulty')->map(fn ($target): array => ['level_id' => $target->level_id, 'percent' => $target->percent])->values()->all(),
        ];

        $topics = $this->topics($examination->course_id);

        return [
            'blueprint' => $data,
            'report' => $this->checker->check($examination, $blueprint),
            'topics' => $topics,
            'types' => QuestionType::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name', 'family'])->map(fn (QuestionType $type): array => [
                'id' => $type->id, 'code' => $type->code, 'name' => $type->name, 'family' => $type->family,
            ])->all(),
            'cognitiveLevels' => CognitiveLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name'])->all(),
            'difficultyLevels' => DifficultyLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name'])->all(),
            'availability' => $this->availability->matrix($examination->branch_id, $examination->course_id, $examination->exam_type_id),
            'limits' => [
                'maxRows' => (int) config('exam.blueprint.max_rows'),
                'maxSections' => (int) config('exam.blueprint.max_sections'),
                'maxCount' => (int) config('exam.blueprint.max_count_per_row'),
                'marksMax' => (float) config('qbank.marks.max'),
            ],
            'forEditing' => $forEditing,
        ];
    }

    /**
     * The topics of a course as a reader goes down them, each with the path that leads to it.
     *
     * @return list<array{id: int, label: string, name: string, level: string, depth: int}>
     */
    private function topics(int $courseId): array
    {
        $nodes = $this->academic->curriculum($courseId);
        $byId = [];
        foreach ($nodes as $node) {
            $byId[$node['id']] = $node;
        }

        return array_map(function (array $node) use ($byId): array {
            $names = [$node['name']];
            $parent = $node['parent_id'];
            for ($guard = 0; $parent !== null && isset($byId[$parent]) && $guard < 20; $guard++) {
                array_unshift($names, $byId[$parent]['name']);
                $parent = $byId[$parent]['parent_id'];
            }

            return [
                'id' => $node['id'],
                'label' => implode(' → ', $names),
                'name' => $node['name'],
                'level' => $node['level'],
                'depth' => $node['depth'],
            ];
        }, $nodes);
    }
}
