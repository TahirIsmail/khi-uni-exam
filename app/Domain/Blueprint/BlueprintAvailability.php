<?php

namespace App\Domain\Blueprint;

use App\Support\Cms\CmsAcademic;
use Illuminate\Support\Facades\DB;

/**
 * How many questions the question bank can give a blueprint: for every topic of the course and every
 * type of question, the questions in use (active, not archived) filed under that examination type
 * for that topic or anything below it.
 *
 * A heading counts what is under it, so a row asking for five multiple-choice questions from
 * "Anatomy" is measured against everything in Anatomy.
 */
final class BlueprintAvailability
{
    public function __construct(private readonly CmsAcademic $academic) {}

    /**
     * @return array<int, array<int, int>> topic id => type id => questions available
     */
    public function matrix(int $branchId, int $courseId, int $examTypeId): array
    {
        $own = DB::table('qb_questions as q')
            ->join('qb_question_versions as v', 'v.id', '=', 'q.active_version_id')
            ->where('q.branch_id', $branchId)
            ->where('q.course_id', $courseId)
            ->where('q.is_archived', false)
            ->where('v.status', 'active')
            ->where('v.exam_type_id', $examTypeId)
            ->groupBy('v.node_id', 'v.question_type_id')
            ->selectRaw('v.node_id, v.question_type_id, COUNT(*) AS total') // raw-sql-reviewed: constant aggregate, no input
            ->get();

        if ($own->isEmpty()) {
            return [];
        }

        $parents = [];
        foreach ($this->academic->curriculum($courseId) as $node) {
            $parents[$node['id']] = $node['parent_id'];
        }

        // Each question counts for its own topic and for every heading above it.
        $matrix = [];
        foreach ($own as $row) {
            $nodeId = (int) $row->node_id;
            $typeId = (int) $row->question_type_id;
            $total = (int) $row->total;

            for ($guard = 0; $nodeId !== 0 && $guard < 20; $guard++) {
                $matrix[$nodeId][$typeId] = ($matrix[$nodeId][$typeId] ?? 0) + $total;
                $nodeId = (int) ($parents[$nodeId] ?? 0);
            }
        }

        return $matrix;
    }
}
