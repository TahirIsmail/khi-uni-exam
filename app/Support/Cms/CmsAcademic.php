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
     * @return list<array{id: int, name: string, code: string}>
     */
    public function programmes(int $branchId): array
    {
        return array_values(DB::connection('cms')->table('v_cms_programmes')
            ->where('branch_id', $branchId)->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name, 'code' => (string) $row->code])
            ->all());
    }

    /**
     * Courses of a campus, optionally of one programme, that questions can be written for.
     *
     * @return list<array{id: int, code: string, title: string, programme_id: int, professional_id: int|null, term_id: int|null}>
     */
    public function courses(int $branchId, ?int $programmeId = null): array
    {
        $query = DB::connection('cms')->table('v_cms_courses')
            ->where('branch_id', $branchId)
            ->where('status', 'active');

        if ($programmeId !== null) {
            $query->where('programme_id', $programmeId);
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
     * @return list<array{id: int, parent_id: int|null, name: string, code: string|null, path: string, depth: int, level: string, discipline_id: int|null, allows_questions: bool}>
     */
    public function curriculum(int $courseId): array
    {
        return array_values(DB::connection('cms')->table('v_cms_curriculum_nodes')
            ->where('course_id', $courseId)
            ->where('is_active', 1)
            ->orderBy('path')
            ->get(['id', 'parent_id', 'name', 'code', 'path', 'depth', 'level_code', 'discipline_id', 'allow_questions'])
            ->map(fn (stdClass $row): array => [
                'id' => (int) $row->id,
                'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
                'name' => (string) $row->name,
                'code' => $row->code === null ? null : (string) $row->code,
                'path' => (string) $row->path,
                'depth' => (int) $row->depth,
                'level' => (string) $row->level_code,
                'discipline_id' => $row->discipline_id === null ? null : (int) $row->discipline_id,
                'allows_questions' => (int) $row->allow_questions === 1,
            ])
            ->all());
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

    /** A course's title and code, for headings and lists. */
    public function courseLabel(int $courseId): ?string
    {
        $course = DB::connection('cms')->table('v_cms_courses')->where('id', $courseId)->first(['course_code', 'title']);

        return $course === null ? null : $course->course_code.' — '.$course->title;
    }
}
