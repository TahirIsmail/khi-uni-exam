<?php

namespace App\Domain\QuestionBank\Queries;

use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Models\User;
use App\Support\Cms\CmsAcademic;
use App\Support\Html\QuestionHtml;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The question list of a campus: the newest version of each question with who wrote it, where it
 * belongs and where it is in the workflow. Only courses the user's exam access allows are shown.
 */
final class QuestionList
{
    public function __construct(
        private readonly CourseScope $courseScope,
        private readonly CmsAcademic $academic,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(User $user, int $branchId, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $courseLabels = [];
        foreach ($this->academic->courses($branchId) as $course) {
            $courseLabels[$course['id']] = $course['code'].' — '.$course['title'];
        }

        return $this->query($user, $branchId, $filters)
            ->select([
                'q.id', 'q.public_ref', 'q.course_id', 'q.is_archived', 'q.times_used',
                'v.id as version_id', 'v.version_no', 'v.status', 'v.stem', 'v.marks', 'v.node_id',
                'v.author_id', 'v.updated_at', 't.name as type_name', 'u.name as author_name',
            ])
            ->orderByDesc('v.updated_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (stdClass $row): array => $this->present($row, $courseLabels, $user));
    }

    /**
     * @param  array<int, string>  $courseLabels
     * @return array<string, mixed>
     */
    private function present(stdClass $row, array $courseLabels, User $user): array
    {
        return [
            'id' => (int) $row->id,
            'reference' => (string) $row->public_ref,
            'versionId' => (int) $row->version_id,
            'versionNo' => (int) $row->version_no,
            'status' => (string) $row->status,
            'statusLabel' => VersionStatus::from((string) $row->status)->label(),
            'type' => (string) $row->type_name,
            'course' => $courseLabels[(int) $row->course_id] ?? ('#'.$row->course_id),
            'marks' => (float) $row->marks,
            'author' => (string) $row->author_name,
            'isMine' => (int) $row->author_id === $user->id,
            'isArchived' => (bool) $row->is_archived,
            'timesUsed' => (int) $row->times_used,
            'summary' => mb_substr(QuestionHtml::toText((string) $row->stem), 0, 160),
            'updatedAt' => $row->updated_at === null ? null : (string) $row->updated_at,
        ];
    }

    /**
     * How many questions sit in each status, for the filter chips.
     *
     * @return array<string, int>
     */
    public function statusCounts(User $user, int $branchId): array
    {
        $rows = $this->query($user, $branchId, [])
            ->groupBy('v.status')
            ->select('v.status')
            ->selectRaw('COUNT(*) AS total') // raw-sql-reviewed: constant aggregate, no input
            ->pluck('total', 'status');

        $counts = [];
        foreach ($rows as $status => $total) {
            $counts[(string) $status] = (int) $total;
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(User $user, int $branchId, array $filters): Builder
    {
        $allowed = $this->courseScope->courseIds($user, $branchId);

        $query = DB::table('qb_questions as q')
            // The newest version of each question; earlier ones are in its history.
            ->join('qb_question_versions as v', function ($join): void {
                $join->on('v.question_id', '=', 'q.id')->on('v.version_no', '=', 'q.latest_version_no');
            })
            ->join('qb_question_types as t', 't.id', '=', 'v.question_type_id')
            ->leftJoin('users as u', 'u.id', '=', 'v.author_id')
            ->where('q.branch_id', $branchId);

        if ($allowed !== null) {
            $query->whereIn('q.course_id', $allowed === [] ? [0] : $allowed);
        }
        if (($filters['course_id'] ?? null) !== null) {
            $query->where('q.course_id', (int) $filters['course_id']);
        }
        if (($filters['status'] ?? '') !== '') {
            $query->where('v.status', (string) $filters['status']);
        }
        if (($filters['mine'] ?? false) === true) {
            $query->where('v.author_id', $user->id);
        }
        if (($filters['search'] ?? '') !== '') {
            $like = '%'.addcslashes((string) $filters['search'], '\\%_').'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('v.search_text', 'like', $like)->orWhere('q.public_ref', 'like', $like);
            });
        }

        return $query;
    }
}
