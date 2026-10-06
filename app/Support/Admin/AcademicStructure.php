<?php

namespace App\Support\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * The academic structure kmu-cms keeps under Academics, kept here in the same tables of the admin
 * database: programmes (classes + acad_programme_profiles), their years (acad_professionals) and
 * semesters, courses, each course's curriculum (subjects, topics, subtopics), and the short lists —
 * intakes, exam types and disciplines. Everything else reads these through the v_cms_* views.
 */
final class AcademicStructure
{
    /** What sits under a course, level by level, and which levels take questions (as kmu-cms has it). */
    private const TEMPLATES = [
        'modular' => [['discipline', true], ['topic', true], ['subtopic', true]],
        'subject' => [['topic', true], ['subtopic', true]],
    ];

    /** @return array<int, array<string, mixed>> */
    public function programmes(int $branchId): array
    {
        $programmes = AdminTables::query('classes as c')->join(AdminTables::name('acad_programme_profiles as p'), 'p.class_id', '=', 'c.id')
            ->where('c.branch_id', $branchId)->orderBy('c.class')
            ->get(['c.id', 'c.class as name', 'p.code', 'p.calendar_type', 'p.structure_type', 'p.duration_years', 'p.is_active']);
        $ids = $programmes->pluck('id')->all();

        $years = AdminTables::query('acad_professionals')->whereIn('class_id', $ids)->orderBy('sequence')->get()->groupBy('class_id');
        $terms = AdminTables::query('acad_professional_terms as t')->join(AdminTables::name('sections as s'), 's.id', '=', 't.section_id')
            ->join(AdminTables::name('acad_professionals as pr'), 'pr.id', '=', 't.professional_id')->whereIn('pr.class_id', $ids)
            ->orderBy('pr.sequence')->orderBy('t.sequence')->get(['t.id', 't.professional_id', 's.section as name'])->groupBy('professional_id');
        $courses = AdminTables::query('acad_courses')->whereIn('class_id', $ids)->orderBy('course_code')->get()->groupBy('class_id');
        $topicCounts = AdminTables::query('acad_curriculum_nodes')->selectRaw('course_id, COUNT(*) AS n')->groupBy('course_id')->pluck('n', 'course_id'); // raw-sql-reviewed: constant aggregate, no input

        return $programmes->map(fn ($p) => [
            'id' => (int) $p->id,
            'name' => $p->name,
            'code' => $p->code,
            'calendar' => $p->calendar_type,
            'structure' => $p->structure_type,
            'years' => (int) $p->duration_years,
            'isActive' => (int) $p->is_active === 1,
            'professionals' => ($years[$p->id] ?? collect())->map(fn ($y) => [
                'id' => (int) $y->id,
                'name' => $y->name,
                'terms' => ($terms[$y->id] ?? collect())->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name])->values()->all(),
            ])->values()->all(),
            'courses' => ($courses[$p->id] ?? collect())->map(fn ($c) => [
                'id' => (int) $c->id,
                'code' => $c->course_code,
                'title' => $c->title,
                'professionalId' => (int) $c->professional_id,
                'termId' => $c->term_id === null ? null : (int) $c->term_id,
                'creditHours' => $c->credit_hours === null ? null : (float) $c->credit_hours,
                'status' => $c->status,
                'topics' => (int) ($topicCounts[$c->id] ?? 0),
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * A new programme with its years (and, for a semester programme, two semesters a year) and the
     * levels its courses' curricula have.
     *
     * @param  array{name: string, code: string, calendar: string, structure: string, years: int}  $data
     */
    public function createProgramme(int $branchId, array $data): int
    {
        if (AdminTables::query('classes')->where('branch_id', $branchId)->where('class', $data['name'])->exists()) {
            throw ValidationException::withMessages(['name' => 'This campus already has a programme with that name.']);
        }

        return DB::transaction(function () use ($data, $branchId): int {
            $id = (int) AdminTables::query('classes')->insertGetId(['branch_id' => $branchId, 'class' => $data['name'], 'is_active' => 'yes']);
            AdminTables::query('acad_programme_profiles')->insert([
                'class_id' => $id, 'code' => $data['code'], 'calendar_type' => $data['calendar'],
                'structure_type' => $data['structure'], 'duration_years' => $data['years'], 'is_active' => 1,
            ]);

            foreach (self::TEMPLATES[$data['structure']] as $depth => [$level, $takesQuestions]) {
                AdminTables::query('acad_level_templates')->insert([
                    'class_id' => $id, 'depth' => $depth + 1, 'level_type_id' => $this->levelTypeId($level), 'allow_questions' => (int) $takesQuestions,
                ]);
            }

            $semester = 0;
            for ($year = 1; $year <= $data['years']; $year++) {
                $professionalId = (int) AdminTables::query('acad_professionals')->insertGetId([
                    'class_id' => $id, 'code' => 'Y'.$year, 'name' => 'Year '.$year, 'sequence' => $year, 'is_active' => 1,
                ]);
                if ($data['calendar'] === 'semester') {
                    foreach ([1, 2] as $sequence) {
                        AdminTables::query('acad_professional_terms')->insert([
                            'professional_id' => $professionalId, 'section_id' => $this->sectionId($branchId, 'Semester '.(++$semester)), 'sequence' => $sequence, 'is_active' => 1,
                        ]);
                    }
                }
            }

            return $id;
        });
    }

    /** @param array{name: string, code: string, is_active: bool} $data */
    public function updateProgramme(int $programme, array $data): void
    {
        DB::transaction(function () use ($programme, $data): void {
            AdminTables::query('classes')->where('id', $programme)->update(['class' => $data['name']]);
            AdminTables::query('acad_programme_profiles')->where('class_id', $programme)->update(['code' => $data['code'], 'is_active' => (int) $data['is_active']]);
        });
    }

    /** The programme, if it belongs to the campus: its id, calendar and structure. */
    public function programme(int $branchId, int $programme): ?stdClass
    {
        return AdminTables::query('classes as c')->join(AdminTables::name('acad_programme_profiles as p'), 'p.class_id', '=', 'c.id')
            ->where('c.id', $programme)->where('c.branch_id', $branchId)->first(['c.id', 'p.calendar_type', 'p.structure_type']);
    }

    /** The course, if it belongs to the campus. */
    public function course(int $branchId, int $course): ?stdClass
    {
        return AdminTables::query('acad_courses as co')->join(AdminTables::name('classes as c'), 'c.id', '=', 'co.class_id')
            ->where('co.id', $course)->where('c.branch_id', $branchId)->first(['co.*']);
    }

    /** @param array<string, mixed> $data */
    public function createCourse(stdClass $programme, array $data, ?int $staffId): int
    {
        return (int) AdminTables::query('acad_courses')->insertGetId($data + [
            'class_id' => $programme->id,
            'course_kind' => $programme->structure_type === 'modular' ? 'module' : 'course',
            'status' => 'active',
            'created_by' => $staffId,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function updateCourse(int $course, array $data, ?int $staffId): void
    {
        AdminTables::query('acad_courses')->where('id', $course)->update($data + ['updated_by' => $staffId]);
    }

    /** @return array<string, mixed> */
    public function curriculum(stdClass $course): array
    {
        $programme = AdminTables::query('classes')->where('id', $course->class_id)->value('class');

        return [
            'course' => ['id' => (int) $course->id, 'code' => $course->course_code, 'title' => $course->title, 'programme' => $programme],
            'levels' => AdminTables::query('acad_level_templates as lt')->join(AdminTables::name('acad_level_types as ty'), 'ty.id', '=', 'lt.level_type_id')
                ->where('lt.class_id', $course->class_id)->orderBy('lt.depth')
                ->get(['lt.depth', 'ty.code', 'ty.name', 'lt.allow_questions'])
                ->map(fn ($l) => ['depth' => (int) $l->depth, 'code' => $l->code, 'name' => $l->name, 'takesQuestions' => (int) $l->allow_questions === 1])->values()->all(),
            'nodes' => AdminTables::query('acad_curriculum_nodes')->where('course_id', $course->id)->orderBy('depth')->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'parent_id', 'name', 'code', 'depth', 'discipline_id', 'is_active'])
                ->map(fn ($n) => [
                    'id' => (int) $n->id, 'parentId' => $n->parent_id === null ? null : (int) $n->parent_id, 'name' => $n->name,
                    'code' => $n->code, 'depth' => (int) $n->depth, 'disciplineId' => $n->discipline_id === null ? null : (int) $n->discipline_id,
                    'isActive' => (int) $n->is_active === 1,
                ])->values()->all(),
            'disciplines' => AdminTables::query('acad_disciplines')->where('is_active', 1)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($d) => ['id' => (int) $d->id, 'name' => $d->name])->values()->all(),
        ];
    }

    /**
     * Adds curriculum items under a parent (or at the top), one per name; names already there are
     * skipped. Returns how many were added.
     *
     * @param  list<string>  $names
     */
    public function addNodes(stdClass $course, ?int $parentId, array $names, ?int $disciplineId): int
    {
        $parent = null;
        if ($parentId !== null) {
            $parent = AdminTables::query('acad_curriculum_nodes')->where('id', $parentId)->where('course_id', $course->id)->first();
            if ($parent === null) {
                throw ValidationException::withMessages(['names' => 'That item is not part of this course.']);
            }
        }
        $depth = $parent === null ? 1 : (int) $parent->depth + 1;
        $levelTypeId = AdminTables::query('acad_level_templates')->where('class_id', $course->class_id)->where('depth', $depth)->value('level_type_id');
        if ($levelTypeId === null) {
            throw ValidationException::withMessages(['names' => 'Nothing more can go under this level.']);
        }

        $siblings = AdminTables::query('acad_curriculum_nodes')->where('course_id', $course->id)->where('parent_key', $parent->id ?? 0);
        $existing = (clone $siblings)->pluck('name')->map(fn ($n) => mb_strtolower((string) $n))->all();
        $sort = (int) (clone $siblings)->max('sort_order');

        return DB::transaction(function () use ($names, $existing, $course, $parent, $levelTypeId, $depth, $disciplineId, $sort): int {
            $added = 0;
            foreach ($names as $name) {
                if (in_array(mb_strtolower($name), $existing, true) || mb_strlen($name) > 200) {
                    continue;
                }
                $existing[] = mb_strtolower($name);
                AdminTables::query('acad_curriculum_nodes')->insert([
                    'course_id' => $course->id,
                    'parent_id' => $parent?->id,
                    'level_type_id' => $levelTypeId,
                    'discipline_id' => $disciplineId ?? $parent?->discipline_id,
                    'name' => $name,
                    'path' => $parent === null ? '/' : $parent->path.$parent->id.'/',
                    'depth' => $depth,
                    'sort_order' => $sort + (++$added),
                    'is_active' => 1,
                ]);
            }

            return $added;
        });
    }

    public function node(int $node): ?stdClass
    {
        return AdminTables::query('acad_curriculum_nodes')->where('id', $node)->first();
    }

    /** @param array{name: string, code: string|null, discipline_id: int|null, is_active: bool} $data */
    public function updateNode(int $node, array $data): void
    {
        AdminTables::query('acad_curriculum_nodes')->where('id', $node)->update([
            'name' => $data['name'], 'code' => $data['code'], 'discipline_id' => $data['discipline_id'], 'is_active' => (int) $data['is_active'],
        ]);
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function lists(int $branchId): array
    {

        return [
            'intakes' => AdminTables::query('sessions')->where('branch_id', $branchId)->orderByDesc('start_date')->orderByDesc('id')->get()
                ->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->session, 'startDate' => $s->start_date, 'endDate' => $s->end_date])->values()->all(),
            'examTypes' => AdminTables::query('acad_exam_types')->orderBy('sort_order')->get()
                ->map(fn ($t) => ['id' => (int) $t->id, 'code' => $t->code, 'name' => $t->name, 'calendar' => $t->calendar_type, 'isResit' => (int) $t->is_resit === 1, 'isActive' => (int) $t->is_active === 1])->values()->all(),
            'disciplines' => AdminTables::query('acad_disciplines')->orderBy('name')->get()
                ->map(fn ($d) => ['id' => (int) $d->id, 'code' => $d->code, 'name' => $d->name, 'isActive' => (int) $d->is_active === 1])->values()->all(),
        ];
    }

    /** @param array{session: string, start_date: string|null, end_date: string|null} $data */
    public function saveIntake(int $branchId, ?int $intake, array $data): void
    {
        if ($intake === null) {
            AdminTables::query('sessions')->insert($data + ['branch_id' => $branchId, 'is_active' => 'yes']);

            return;
        }
        abort_unless(AdminTables::query('sessions')->where('id', $intake)->where('branch_id', $branchId)->exists(), 404);
        AdminTables::query('sessions')->where('id', $intake)->update($data);
    }

    /** @param array<string, mixed> $data */
    public function saveExamType(?int $type, array $data): void
    {
        if ($type === null) {
            AdminTables::query('acad_exam_types')->insert($data + ['sort_order' => (int) AdminTables::query('acad_exam_types')->max('sort_order') + 1]);

            return;
        }
        abort_unless(AdminTables::query('acad_exam_types')->where('id', $type)->exists(), 404);
        AdminTables::query('acad_exam_types')->where('id', $type)->update($data);
    }

    /** @param array<string, mixed> $data */
    public function saveDiscipline(?int $discipline, array $data): void
    {
        if ($discipline === null) {
            AdminTables::query('acad_disciplines')->insert($data);

            return;
        }
        abort_unless(AdminTables::query('acad_disciplines')->where('id', $discipline)->exists(), 404);
        AdminTables::query('acad_disciplines')->where('id', $discipline)->update($data);
    }

    private function levelTypeId(string $code): int
    {
        return (int) (AdminTables::query('acad_level_types')->where('code', $code)->value('id')
            ?? AdminTables::query('acad_level_types')->insertGetId(['code' => $code, 'name' => ucfirst($code)]));
    }

    private function sectionId(int $branchId, string $name): int
    {
        return (int) (AdminTables::query('sections')->where('branch_id', $branchId)->where('section', $name)->value('id')
            ?? AdminTables::query('sections')->insertGetId(['branch_id' => $branchId, 'section' => $name, 'is_active' => 'yes']));
    }
}
