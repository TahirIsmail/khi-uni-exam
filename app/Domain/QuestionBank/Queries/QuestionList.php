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
 * Searching the question bank of a campus (blueprint 12): the newest version of each question with
 * who wrote it, where it belongs and where it is in the workflow.
 *
 * Words are matched against the plain-text copy of each version with MySQL's FULLTEXT index, and
 * also with a plain "contains" search, so part of a word or a reference still finds the question.
 * Only courses the user's exam access allows are ever searched.
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
        $sort = (string) ($filters['sort'] ?? '');
        $search = (string) ($filters['search'] ?? '');
        $courseLabels = $this->academic->courseLabels($branchId);
        $examTypes = [];
        foreach ($this->academic->examTypes() as $examType) {
            $examTypes[$examType['id']] = $examType['name'];
        }

        return $this->query($user, $branchId, $filters)
            ->select([
                'q.id', 'q.public_ref', 'q.course_id', 'q.is_archived', 'q.times_used',
                'v.id as version_id', 'v.version_no', 'v.status', 'v.stem', 'v.marks', 'v.node_id',
                'v.author_id', 'v.updated_at', 'v.content_hash', 'v.discipline_id', 'v.decision_code',
                'v.cognitive_level_id', 'v.difficulty_level_id', 'v.exam_type_id',
                't.name as type_name', 'u.name as author_name',
            ])
            // How well each row matches the words, so the best matches can come first.
            ->when(
                $search !== '',
                fn (Builder $query): Builder => $query->selectRaw('MATCH (v.search_text) AGAINST (? IN NATURAL LANGUAGE MODE) AS relevance', [$search]), // raw-sql-reviewed: the words are a bound parameter
                fn (Builder $query): Builder => $query->selectRaw('0 AS relevance'), // raw-sql-reviewed: constant
            )
            ->when($sort === 'marks', fn (Builder $query): Builder => $query->orderByDesc('v.marks'))
            ->when($sort === 'reference', fn (Builder $query): Builder => $query->orderBy('q.public_ref'))
            ->when($sort === 'oldest', fn (Builder $query): Builder => $query->orderBy('v.updated_at'))
            ->when(! in_array($sort, ['marks', 'reference', 'oldest'], true), function (Builder $query) use ($filters): Builder {
                // With words to match, the best matches come first; otherwise the newest work.
                return ($filters['search'] ?? '') !== ''
                    ? $query->orderByDesc('relevance')->orderByDesc('v.updated_at')
                    : $query->orderByDesc('v.updated_at');
            })
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (stdClass $row): array => $this->present($row, $courseLabels, $examTypes, $user));
    }

    /**
     * The versions an export should contain: exactly what this search would show, newest first,
     * with a hard limit so one click cannot drain the bank.
     *
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    public function versionIdsFor(User $user, int $branchId, array $filters, int $limit): array
    {
        $ids = $this->query($user, $branchId, $filters)
            ->orderByDesc('v.updated_at')
            ->limit($limit)
            ->pluck('v.id');

        return array_values(array_map(fn (mixed $id): int => (int) $id, $ids->all()));
    }

    /**
     * @param  array<int, string>  $courseLabels
     * @param  array<int, string>  $examTypes
     * @return array<string, mixed>
     */
    private function present(stdClass $row, array $courseLabels, array $examTypes, User $user): array
    {
        return [
            'id' => (int) $row->id,
            'reference' => (string) $row->public_ref,
            'versionId' => (int) $row->version_id,
            'versionNo' => (int) $row->version_no,
            'status' => (string) $row->status,
            'statusLabel' => VersionStatus::kmuLabel(VersionStatus::from((string) $row->status), $row->decision_code === null ? null : (string) $row->decision_code),
            'type' => (string) $row->type_name,
            'course' => $courseLabels[(int) $row->course_id] ?? ('#'.$row->course_id),
            'examType' => $row->exam_type_id === null ? null : ($examTypes[(int) $row->exam_type_id] ?? null),
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
     * The people who have written questions in this campus, for the author filter.
     *
     * @return list<array{id: int, name: string}>
     */
    public function authors(User $user, int $branchId): array
    {
        $rows = $this->query($user, $branchId, [])
            ->join('users as author', 'author.id', '=', 'v.author_id')
            ->distinct()
            ->orderBy('author.name')
            ->get(['author.id', 'author.name']);

        return array_values($rows->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])->all());
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
     * Narrows the search to one of KMU's statuses (VersionStatus::groups()).
     */
    private function whereKmuStatus(Builder $query, string $key): void
    {
        $in = fn (VersionStatus ...$statuses): array => array_map(fn (VersionStatus $status): string => $status->value, $statuses);
        $reviewing = $in(VersionStatus::Submitted, VersionStatus::UnderReview);
        $stored = $in(VersionStatus::Approved, VersionStatus::Active);

        if ($key === 'removed') {
            $query->where(fn (Builder $inner) => $inner->where('q.is_archived', true)
                ->orWhereIn('v.status', $in(VersionStatus::Archived, VersionStatus::Retired)));

            return;
        }

        $query->where('q.is_archived', false);

        match ($key) {
            'draft' => $query->where('v.status', VersionStatus::Draft->value),
            'submitted' => $query->whereIn('v.status', $reviewing)->where(fn (Builder $inner) => $inner->whereNull('v.decision_code')->orWhere('v.decision_code', '!=', 'review')),
            'review' => $query->where(fn (Builder $inner) => $inner->where('v.status', VersionStatus::OnHold->value)
                ->orWhere(fn (Builder $again) => $again->whereIn('v.status', $reviewing)->where('v.decision_code', 'review'))),
            'revise' => $query->where('v.status', VersionStatus::ChangesRequested->value),
            'accept' => $query->whereIn('v.status', $stored)->where(fn (Builder $inner) => $inner->whereNull('v.decision_code')->orWhere('v.decision_code', '!=', 'retain')),
            'retain' => $query->whereIn('v.status', $stored)->where('v.decision_code', 'retain'),
            default => null,
        };
    }

    /**
     * How many questions are in each of KMU's statuses, in the university's order.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    public function statusGroupCounts(User $user, int $branchId): array
    {
        $groups = [];
        foreach (VersionStatus::groups() as $key => $label) {
            $groups[] = [
                'key' => $key,
                'label' => $label,
                'count' => $this->query($user, $branchId, ['status' => $key])->count(),
            ];
        }

        return $groups;
    }

    /** How many of the campus's questions this person wrote. */
    public function mineCount(User $user, int $branchId): int
    {
        return $this->query($user, $branchId, ['mine' => true])->count();
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
        // A KMU status covers one or more workflow steps, and the decision taken tells some apart.
        // "Remove / Discard" is also where archived questions are found; every other view leaves them out.
        $status = ($filters['archived'] ?? false) === true ? 'removed' : (string) ($filters['status'] ?? '');
        $this->whereKmuStatus($query, $status);
        if (($filters['mine'] ?? false) === true) {
            $query->where('v.author_id', $user->id);
        }
        if (($filters['programme_id'] ?? null) !== null) {
            $query->where('v.programme_id', (int) $filters['programme_id']);
        }
        if (($filters['year'] ?? '') !== '') {
            // "12" is a year of an annual programme, "12-3" one term of a year of a semester programme.
            [$professionalId, $termId] = array_pad(explode('-', (string) $filters['year'], 2), 2, null);
            $query->where('v.professional_id', (int) $professionalId);
            $termId === null ? $query->whereNull('v.term_id') : $query->where('v.term_id', (int) $termId);
        }
        if (($filters['exam_type_id'] ?? null) !== null) {
            $query->where('v.exam_type_id', (int) $filters['exam_type_id']);
        }
        if (($filters['used'] ?? '') === 'used') {
            $query->where(fn (Builder $inner) => $inner->where('q.times_used', '>', 0)
                ->orWhereExists(fn (Builder $usage) => $usage->from('qb_question_usage as qu')->whereColumn('qu.question_id', 'q.id')));
        }
        if (($filters['used'] ?? '') === 'unused') {
            $query->where('q.times_used', 0)
                ->whereNotExists(fn (Builder $usage) => $usage->from('qb_question_usage as qu')->whereColumn('qu.question_id', 'q.id'));
        }
        if (($filters['used_from'] ?? null) !== null || ($filters['used_to'] ?? null) !== null) {
            // Used in an examination held between these dates.
            $query->whereExists(fn (Builder $usage) => $usage->from('qb_question_usage as qu')
                ->whereColumn('qu.question_id', 'q.id')
                ->when(($filters['used_from'] ?? null) !== null, fn (Builder $q) => $q->where('qu.used_on', '>=', (string) $filters['used_from']))
                ->when(($filters['used_to'] ?? null) !== null, fn (Builder $q) => $q->where('qu.used_on', '<=', (string) $filters['used_to'])));
        }
        if (($filters['node_id'] ?? null) !== null) {
            // A topic includes everything under it.
            $nodes = $this->academic->nodeSubtreeIds((int) $filters['node_id']);
            $query->whereIn('v.node_id', $nodes === [] ? [0] : $nodes);
        }
        if (($filters['discipline_id'] ?? null) !== null) {
            $query->where('v.discipline_id', (int) $filters['discipline_id']);
        }
        if (($filters['type_id'] ?? null) !== null) {
            $query->where('v.question_type_id', (int) $filters['type_id']);
        }
        if (($filters['cognitive_level_id'] ?? null) !== null) {
            $query->where('v.cognitive_level_id', (int) $filters['cognitive_level_id']);
        }
        if (($filters['difficulty_level_id'] ?? null) !== null) {
            $query->where('v.difficulty_level_id', (int) $filters['difficulty_level_id']);
        }
        if (($filters['author_id'] ?? null) !== null) {
            $query->where('v.author_id', (int) $filters['author_id']);
        }
        if (($filters['tag_id'] ?? null) !== null) {
            $query->whereExists(fn (Builder $inner) => $inner->from('qb_version_tags as vt')
                ->whereColumn('vt.version_id', 'v.id')
                ->where('vt.tag_id', (int) $filters['tag_id']));
        }
        if (($filters['marks_min'] ?? null) !== null) {
            $query->where('v.marks', '>=', (float) $filters['marks_min']);
        }
        if (($filters['marks_max'] ?? null) !== null) {
            $query->where('v.marks', '<=', (float) $filters['marks_max']);
        }
        if (($filters['updated_from'] ?? null) !== null) {
            $query->where('v.updated_at', '>=', (string) $filters['updated_from'].' 00:00:00');
        }
        if (($filters['updated_to'] ?? null) !== null) {
            $query->where('v.updated_at', '<=', (string) $filters['updated_to'].' 23:59:59');
        }

        if (($filters['duplicates'] ?? false) === true) {
            // Questions whose text matches another question in this campus.
            $query->whereExists(fn (Builder $inner) => $inner->from('qb_question_versions as dup')
                ->join('qb_questions as dq', 'dq.id', '=', 'dup.question_id')
                ->whereColumn('dup.content_hash', 'v.content_hash')
                ->whereColumn('dup.question_id', '!=', 'v.question_id')
                ->where('dq.branch_id', $branchId));
        }

        if (($filters['search'] ?? '') !== '') {
            $term = (string) $filters['search'];
            $like = '%'.addcslashes($term, '\\%_').'%';
            $query->where(function (Builder $inner) use ($like, $term): void {
                $inner->where('v.search_text', 'like', $like)
                    ->orWhere('q.public_ref', 'like', $like)
                    ->orWhereRaw('MATCH (v.search_text) AGAINST (? IN NATURAL LANGUAGE MODE)', [$term]); // raw-sql-reviewed: the words are a bound parameter
            });
        }

        return $query;
    }
}
