<?php

namespace App\Domain\Paper;

use App\Domain\Exam\Models\Examination;
use App\Support\Cms\CmsAcademic;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The questions the question bank can give one row of a blueprint: in use (active, not archived), of
 * this examination's campus and course, filed under its examination type, of the row's type, and
 * from the row's topic or anything below it.
 *
 * This is the only place that says what may go into a paper, so the automatic draw, the picker and
 * the checks on every change all agree.
 */
final class CandidatePool
{
    public function __construct(private readonly CmsAcademic $academic) {}

    /**
     * $nodeId 0 is a blueprint row for the whole course: every question of the course counts,
     * whatever subject or topic it is filed under, and those filed on the course itself (BDS, DPT).
     */
    public function query(Examination $examination, int $nodeId, int $typeId): Builder
    {
        $nodes = $nodeId === 0 ? null : $this->academic->nodeSubtreeIds($nodeId);

        return DB::table('qb_questions as q')
            ->join('qb_question_versions as v', 'v.id', '=', 'q.active_version_id')
            ->where('q.branch_id', $examination->branch_id)
            ->where('q.course_id', $examination->course_id)
            ->where('q.is_archived', false)
            ->where('v.status', 'active')
            ->where('v.exam_type_id', $examination->exam_type_id)
            ->where('v.question_type_id', $typeId)
            ->when($nodes !== null, fn (Builder $query) => $query->whereIn('v.node_id', $nodes === [] ? [0] : $nodes))
            ->select([
                'q.id as question_id', 'q.public_ref', 'q.times_used', 'q.last_used_at',
                'v.id as version_id', 'v.version_no', 'v.node_id', 'v.marks', 'v.content_hash',
                'v.cognitive_level_id', 'v.difficulty_level_id', 'v.author_id', 'v.stem', 'v.lead_in',
            ]);
    }

    /** Narrows a query to the questions whose text or reference contains the words. */
    public function search(Builder $query, string $words): Builder
    {
        $words = trim($words);
        if ($words === '') {
            return $query;
        }

        $like = '%'.addcslashes($words, '\\%_').'%';

        return $query->where(fn (Builder $inner) => $inner->where('v.search_text', 'like', $like)->orWhere('q.public_ref', 'like', $like));
    }
}
