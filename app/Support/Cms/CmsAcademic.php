<?php

namespace App\Support\Cms;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The academic structure of kmu-cms, read through the read-only views: what an author can choose
 * from (programmes, professionals, courses, topics, disciplines) and where a chosen topic sits.
 *
 * Everything is limited to one campus, because a question belongs to the campus its author works in.
 */
final class CmsAcademic
{
    /**
     * The campus's programmes. `modular` (MBBS) means a question is filed under a subject of a
     * module; otherwise (BDS, DPT) under the course itself, with a topic if the department has any.
     *
     * `active` false: switched off in kmu-cms — kept, so existing questions and examinations still
     * show its name, but nothing new is filed under it.
     *
     * @return list<array{id: int, name: string, code: string, calendar: string, modular: bool, active: bool}>
     */
    public function programmes(int $branchId): array
    {
        return array_values(DB::connection('cms')->table('v_cms_programmes')
            ->where('branch_id', $branchId)->orderBy('name')
            ->get(['id', 'name', 'code', 'calendar_type', 'structure_type', 'is_active'])
            ->map(fn (stdClass $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'code' => (string) $row->code,
                'calendar' => (string) $row->calendar_type,
                'modular' => $row->structure_type === 'modular',
                'active' => (int) $row->is_active === 1,
            ])
            ->all());
    }

    /** Whether new questions and examinations may be filed under a program (not switched off). */
    public function programmeInUse(int $programmeId): bool
    {
        return (int) DB::connection('cms')->table('v_cms_programmes')->where('id', $programmeId)->value('is_active') === 1;
    }

    /**
     * Courses of a campus, optionally of one programme, that questions can be written for.
     * $inUseOnly leaves out the courses of programs switched off in kmu-cms: for the screens that
     * file something new (a question, an import, an examination), not for those that look after
     * what already exists.
     *
     * @return list<array{id: int, code: string, title: string, programme_id: int, professional_id: int|null, term_id: int|null}>
     */
    public function courses(int $branchId, ?int $programmeId = null, bool $inUseOnly = false): array
    {
        $query = DB::connection('cms')->table('v_cms_courses')
            ->where('branch_id', $branchId)
            ->where('status', 'active');

        if ($programmeId !== null) {
            $query->where('programme_id', $programmeId);
        }
        if ($inUseOnly) {
            $query->whereIn('programme_id', DB::connection('cms')->table('v_cms_programmes')->where('is_active', 1)->select('id'));
        }

        return array_values($query->orderBy('course_code')
            ->get(['id', 'course_code', 'title', 'programme_id', 'professional_id', 'term_id'])
            ->map(fn (stdClass $row): array => [
                'id' => (int) $row->id,
                'code' => (string) $row->course_code,
                'title' => (string) $row->title,
                'programme_id' => (int) $row->programme_id,
                'professional_id' => $row->professional_id === null ? null : (int) $row->professional_id,
                'term_id' => $row->term_id === null ? null : (int) $row->term_id,
            ])
            ->all());
    }

    /**
     * The curriculum tree of a course. `allows_questions` says whether a topic is a level that
     * questions may be attached to (set per programme in kmu-cms).
     *
     * @return list<array{id: int, parent_id: int|null, name: string, code: string|null, path: string, depth: int, sort_order: int, level: string, discipline_id: int|null, allows_questions: bool}>
     */
    public function curriculum(int $courseId): array
    {
        $nodes = DB::connection('cms')->table('v_cms_curriculum_nodes')
            ->where('course_id', $courseId)
            ->where('is_active', 1)
            ->orderBy('depth')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'parent_id', 'name', 'code', 'path', 'depth', 'sort_order', 'level_code', 'discipline_id', 'allow_questions'])
            ->map(fn (stdClass $row): array => [
                'id' => (int) $row->id,
                'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
                'name' => (string) $row->name,
                'code' => $row->code === null ? null : (string) $row->code,
                'path' => (string) $row->path,
                'depth' => (int) $row->depth,
                'sort_order' => (int) $row->sort_order,
                'level' => (string) $row->level_code,
                'discipline_id' => $row->discipline_id === null ? null : (int) $row->discipline_id,
                'allows_questions' => (int) $row->allow_questions === 1,
            ])
            ->all();

        return $this->inTreeOrder(array_values($nodes));
    }

    /**
     * The curriculum as a reader goes down it: each heading followed by what is under it, in the
     * order the department put them in. (The stored path cannot do this on its own — it holds ids,
     * which sort as text, so "/101/" would come before "/99/".)
     *
     * @param  list<array{id: int, parent_id: int|null, name: string, code: string|null, path: string, depth: int, sort_order: int, level: string, discipline_id: int|null, allows_questions: bool}>  $nodes
     * @return list<array{id: int, parent_id: int|null, name: string, code: string|null, path: string, depth: int, sort_order: int, level: string, discipline_id: int|null, allows_questions: bool}>
     */
    private function inTreeOrder(array $nodes): array
    {
        $children = [];
        foreach ($nodes as $node) {
            $children[$node['parent_id'] ?? 0][] = $node;
        }

        $ordered = [];
        $walk = function (int $parentId) use (&$walk, &$ordered, $children): void {
            foreach ($children[$parentId] ?? [] as $node) {
                $ordered[] = $node;
                $walk((int) $node['id']);
            }
        };
        $walk(0);

        // A node whose parent is missing (inactive, say) would be lost, so it is added at the end.
        if (count($ordered) < count($nodes)) {
            $seen = array_column($ordered, 'id');
            foreach ($nodes as $node) {
                if (! in_array($node['id'], $seen, true)) {
                    $ordered[] = $node;
                }
            }
        }

        return $ordered;
    }

    /**
     * The examination types, each with the calendar it belongs to: Annual and Supplementary for
     * annual programmes, Regular and Retake for semester programmes.
     *
     * @return list<array{id: int, code: string, name: string, calendar: string}>
     */
    public function examTypes(): array
    {
        return array_values(DB::connection('cms')->table('v_cms_exam_types')
            ->where('is_active', 1)->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'code', 'name', 'calendar_type'])
            ->map(fn (stdClass $row): array => [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'calendar' => (string) $row->calendar_type,
            ])
            ->all());
    }

    /**
     * Whether an examination type can be used for a programme: Annual and Supplementary belong to
     * annual programmes, Regular and Retake to semester ones, and one for any calendar to all.
     */
    public function examTypeFits(int $examTypeId, int $programmeId): bool
    {
        $calendar = $this->programmeCalendar($programmeId);
        $type = DB::connection('cms')->table('v_cms_exam_types')->where('id', $examTypeId)->where('is_active', 1)->value('calendar_type');

        // An examination type for any calendar (an entry test) fits every programme.
        return $calendar !== null && $type !== null && ((string) $type === $calendar || (string) $type === 'any');
    }

    /** Whether a programme runs on an annual or a semester calendar. */
    public function programmeCalendar(int $programmeId): ?string
    {
        $calendar = DB::connection('cms')->table('v_cms_programmes')->where('id', $programmeId)->value('calendar_type');

        return $calendar === null ? null : (string) $calendar;
    }

    /**
     * The years (professionals) of the campus's programmes and, for semester programmes, their
     * terms — the "Year / Semester" step between the programme and its courses.
     *
     * @return list<array{id: string, programme_id: int, professional_id: int, term_id: int|null, name: string}>
     */
    public function yearsAndTerms(int $branchId): array
    {
        $cms = DB::connection('cms');
        $professionals = $cms->table('v_cms_professionals')
            ->where('branch_id', $branchId)->where('is_active', 1)
            ->orderBy('programme_id')->orderBy('sequence')
            ->get(['id', 'programme_id', 'name']);

        $terms = [];
        foreach ($cms->table('v_cms_professional_terms')->where('is_active', 1)->orderBy('sequence')->get(['id', 'professional_id', 'name']) as $term) {
            $terms[(int) $term->professional_id][] = $term;
        }

        $options = [];
        foreach ($professionals as $professional) {
            $professionalId = (int) $professional->id;

            // A semester programme is chosen down to the term; an annual one only to the year.
            if (isset($terms[$professionalId])) {
                foreach ($terms[$professionalId] as $term) {
                    $options[] = [
                        'id' => $professionalId.'-'.(int) $term->id,
                        'programme_id' => (int) $professional->programme_id,
                        'professional_id' => $professionalId,
                        'term_id' => (int) $term->id,
                        'name' => $professional->name.', '.$term->name,
                    ];
                }

                continue;
            }

            $options[] = [
                'id' => (string) $professionalId,
                'programme_id' => (int) $professional->programme_id,
                'professional_id' => $professionalId,
                'term_id' => null,
                'name' => (string) $professional->name,
            ];
        }

        return $options;
    }

    /**
     * Each programme's calendar, so a screen offers only the examination types it uses.
     *
     * @return array<int, string> programme id => annual|semester
     */
    public function calendars(int $branchId): array
    {
        $calendars = [];
        foreach ($this->programmes($branchId) as $programme) {
            $calendars[$programme['id']] = (string) $this->programmeCalendar($programme['id']);
        }

        return $calendars;
    }

    /**
     * The Year / Semester choices that lead to at least one of the given courses, so nobody is
     * offered a year with nothing in it.
     *
     * @param  list<array{id: int, code: string, title: string, programme_id: int, professional_id: int|null, term_id: int|null}>  $courses
     * @return list<array{id: string, programme_id: int, professional_id: int, term_id: int|null, name: string}>
     */
    public function yearsWithCourses(int $branchId, array $courses): array
    {
        $used = [];
        foreach ($courses as $course) {
            $used[$course['professional_id'].'-'.($course['term_id'] ?? '')] = true;
        }

        return array_values(array_filter(
            $this->yearsAndTerms($branchId),
            fn (array $year): bool => isset($used[$year['professional_id'].'-'.($year['term_id'] ?? '')]),
        ));
    }

    /**
     * Where a course sits: its campus, programme, year and term. Null when there is no such course.
     *
     * @return array{branch_id: int, programme_id: int, professional_id: int|null, term_id: int|null, course_id: int, status: string, label: string}|null
     */
    public function placeOfCourse(int $courseId): ?array
    {
        $course = DB::connection('cms')->table('v_cms_courses')->where('id', $courseId)
            ->first(['branch_id', 'programme_id', 'professional_id', 'term_id', 'status', 'course_code', 'title']);
        if ($course === null || $course->branch_id === null) {
            return null;
        }

        return [
            'branch_id' => (int) $course->branch_id,
            'programme_id' => (int) $course->programme_id,
            'professional_id' => $course->professional_id === null ? null : (int) $course->professional_id,
            'term_id' => $course->term_id === null ? null : (int) $course->term_id,
            'course_id' => $courseId,
            'status' => (string) $course->status,
            'label' => $course->course_code.' — '.$course->title,
        ];
    }

    /**
     * The academic sessions (intakes) of a campus, newest first.
     *
     * @return list<array{id: int, name: string}>
     */
    public function intakes(int $branchId): array
    {
        return array_values(DB::connection('cms')->table('v_cms_intakes')
            ->where('branch_id', $branchId)->orderByDesc('start_date')->orderByDesc('id')
            ->get(['id', 'name'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all());
    }

    /**
     * "Year, Semester" for a professional (and term), as the Year / Semester lists name it.
     */
    public function yearName(int $branchId, int $professionalId, ?int $termId): ?string
    {
        foreach ($this->yearsAndTerms($branchId) as $year) {
            if ($year['professional_id'] === $professionalId && $year['term_id'] === $termId) {
                return $year['name'];
            }
        }

        return null;
    }

    /**
     * @return list<array{id: int, code: string, name: string}>
     */
    public function disciplines(): array
    {
        return array_values(DB::connection('cms')->table('v_cms_disciplines')
            ->where('is_active', 1)->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name])
            ->all());
    }

    /**
     * Where a topic sits: its course, term, professional, programme and campus. Null when the topic
     * does not exist, is not in that course, or is not a level questions may be attached to.
     *
     * @return array{branch_id: int, programme_id: int, professional_id: int|null, term_id: int|null, course_id: int, node_id: int, discipline_id: int|null}|null
     */
    public function placeOfNode(int $nodeId, int $courseId): ?array
    {
        $cms = DB::connection('cms');
        $node = $cms->table('v_cms_curriculum_nodes')->where('id', $nodeId)->first(['course_id', 'discipline_id', 'allow_questions', 'is_active']);
        if ($node === null || (int) $node->course_id !== $courseId || (int) $node->allow_questions !== 1 || (int) $node->is_active !== 1) {
            return null;
        }

        $course = $cms->table('v_cms_courses')->where('id', $courseId)->first(['branch_id', 'programme_id', 'professional_id', 'term_id']);
        if ($course === null) {
            return null;
        }

        return [
            'branch_id' => (int) $course->branch_id,
            'programme_id' => (int) $course->programme_id,
            'professional_id' => $course->professional_id === null ? null : (int) $course->professional_id,
            'term_id' => $course->term_id === null ? null : (int) $course->term_id,
            'course_id' => $courseId,
            'node_id' => $nodeId,
            'discipline_id' => $node->discipline_id === null ? null : (int) $node->discipline_id,
        ];
    }

    /**
     * Where a question is filed: under a subject or topic ($nodeId), or on the module or course as a
     * whole ($nodeId null — KMU: Islamiyat and other non-modular subjects of MBBS have no subject).
     * Null when that place cannot take questions.
     *
     * @return array{branch_id: int, programme_id: int, professional_id: int|null, term_id: int|null, course_id: int, node_id: int|null, discipline_id: int|null}|null
     */
    public function placeOf(?int $nodeId, int $courseId): ?array
    {
        if ($nodeId !== null) {
            return $this->placeOfNode($nodeId, $courseId);
        }

        $course = $this->placeOfCourse($courseId);
        if ($course === null || $course['status'] === 'retired') {
            return null;
        }

        return [
            'branch_id' => $course['branch_id'],
            'programme_id' => $course['programme_id'],
            'professional_id' => $course['professional_id'],
            'term_id' => $course['term_id'],
            'course_id' => $courseId,
            'node_id' => null,
            'discipline_id' => null,
        ];
    }

    /** Whether a programme is modular (MBBS: module > subject). */
    public function isModular(int $programmeId): bool
    {
        return DB::connection('cms')->table('v_cms_programmes')->where('id', $programmeId)->value('structure_type') === 'modular';
    }

    /** Whether an academic session (intake) belongs to the campus. */
    public function intakeBelongs(int $intakeId, int $branchId): bool
    {
        return DB::connection('cms')->table('v_cms_intakes')->where('id', $intakeId)->where('branch_id', $branchId)->exists();
    }

    /**
     * A topic and everything under it, so searching a topic also finds its subtopics.
     *
     * @return list<int>
     */
    public function nodeSubtreeIds(int $nodeId): array
    {
        $node = DB::connection('cms')->table('v_cms_curriculum_nodes')->where('id', $nodeId)->first(['id', 'path']);
        if ($node === null) {
            return [];
        }

        $childPath = rtrim((string) $node->path, '/').'/'.$nodeId.'/';
        $ids = DB::connection('cms')->table('v_cms_curriculum_nodes')
            ->where('path', 'like', addcslashes($childPath, '\\%_').'%')
            ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        return array_values(array_unique([$nodeId, ...$ids]));
    }

    /**
     * A subject's or topic's name, for headings and comparisons. No node (null, or 0 in a blueprint
     * row) is the course as a whole.
     */
    public function nodeName(?int $nodeId): ?string
    {
        if ($nodeId === null || $nodeId === 0) {
            return 'The whole course';
        }

        $name = DB::connection('cms')->table('v_cms_curriculum_nodes')->where('id', $nodeId)->value('name');

        return $name === null ? null : (string) $name;
    }

    /**
     * "Code — title" for every course of a campus, retired ones too: a question written for a
     * course that has since been retired still shows where it belongs.
     *
     * @return array<int, string>
     */
    public function courseLabels(int $branchId): array
    {
        $labels = [];
        foreach (DB::connection('cms')->table('v_cms_courses')->where('branch_id', $branchId)->get(['id', 'course_code', 'title']) as $course) {
            $labels[(int) $course->id] = $course->course_code.' — '.$course->title;
        }

        return $labels;
    }

    /** A course's title and code, for headings and lists. */
    public function courseLabel(int $courseId): ?string
    {
        $course = DB::connection('cms')->table('v_cms_courses')->where('id', $courseId)->first(['course_code', 'title']);

        return $course === null ? null : $course->course_code.' — '.$course->title;
    }
}
