<?php

namespace App\Domain\Exam\Queries;

use App\Domain\Blueprint\Enums\BlueprintStatus;
use App\Domain\Exam\Models\Examination;
use App\Domain\QuestionBank\Queries\CourseScope;
use App\Models\User;
use App\Support\Cms\CmsAcademic;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The examinations of the campus being worked in, limited to the courses the user's exam access
 * allows — the same limit the question bank applies — with the filters the list screen offers.
 */
final class ExaminationList
{
    public function __construct(
        private readonly CourseScope $courseScope,
        private readonly CmsAcademic $academic,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(User $user, int $branchId, array $filters): LengthAwarePaginator
    {
        $page = $this->query($user, $branchId, $filters)
            ->select('exm_examinations.*')
            ->orderByDesc('exm_examinations.id')
            ->paginate(15)
            ->withQueryString();

        $ids = $page->getCollection()->pluck('id')->all();
        $statuses = $ids === [] ? [] : DB::table('exm_blueprints')->whereIn('examination_id', $ids)->pluck('status', 'examination_id')->all();
        $planned = $ids === [] ? collect() : DB::table('exm_blueprint_rows as r')
            ->join('exm_blueprints as b', 'b.id', '=', 'r.blueprint_id')
            ->whereIn('b.examination_id', $ids)
            ->groupBy('b.examination_id')
            ->selectRaw('b.examination_id, SUM(r.question_count) AS questions, SUM(r.question_count * r.marks_each) AS marks') // raw-sql-reviewed: aggregates over columns, ids are bound
            ->get()
            ->mapWithKeys(fn (stdClass $row): array => [(int) $row->examination_id => ['questions' => (int) $row->questions, 'marks' => round((float) $row->marks, 2)]]);

        $names = [
            'programmes' => collect($this->academic->programmes($branchId))->pluck('name', 'id'),
            'types' => collect($this->academic->examTypes())->pluck('name', 'id'),
            'courses' => $this->academic->courseLabels($branchId),
        ];

        return $page->through(fn (Examination $exam): array => $this->present(
            $exam,
            BlueprintStatus::tryFrom((string) ($statuses[$exam->id] ?? '')) ?? BlueprintStatus::Draft,
            $planned->get($exam->id),
            $names,
        ));
    }

    /**
     * @param  array{questions: int, marks: float}|null  $plan
     * @param  array{programmes: Collection<int|string, mixed>, types: Collection<int|string, mixed>, courses: array<int, string>}  $names
     * @return array<string, mixed>
     */
    private function present(Examination $exam, BlueprintStatus $stage, ?array $plan, array $names): array
    {
        return [
            'id' => $exam->id,
            'reference' => $exam->public_ref,
            'title' => $exam->title,
            'programme' => (string) ($names['programmes'][$exam->programme_id] ?? ''),
            'year' => $this->academic->yearName($exam->branch_id, $exam->professional_id, $exam->term_id) ?? '',
            'examType' => (string) ($names['types'][$exam->exam_type_id] ?? ''),
            'course' => (string) ($names['courses'][$exam->course_id] ?? ''),
            'startsAt' => $exam->starts_at?->setTimezone((string) config('exam.timezone'))->format('D j M Y, H:i'),
            'durationMinutes' => $exam->duration_minutes,
            'totalMarks' => $exam->total_marks,
            'blueprintStatus' => $stage->value,
            'blueprintLabel' => $stage->label(),
            'plannedQuestions' => $plan['questions'] ?? 0,
            'plannedMarks' => $plan['marks'] ?? 0.0,
        ];
    }

    /**
     * How many blueprints are waiting for this person to approve: submitted, in their reach, and not
     * written or submitted by them.
     */
    public function awaitingApproval(User $user, int $branchId): int
    {
        return $this->query($user, $branchId, [])
            ->whereHas('blueprint', fn (Builder $inner) => $inner->where('status', BlueprintStatus::Submitted->value)
                ->where('created_by', '!=', $user->id)
                ->where('submitted_by', '!=', $user->id))
            ->count();
    }

    /**
     * How many examinations are at each stage of their blueprint, for the chips above the list.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    public function stageCounts(User $user, int $branchId): array
    {
        $counts = $this->query($user, $branchId, [])
            ->join('exm_blueprints', 'exm_blueprints.examination_id', '=', 'exm_examinations.id')
            ->groupBy('exm_blueprints.status')
            ->selectRaw('exm_blueprints.status AS stage, COUNT(*) AS total') // raw-sql-reviewed: constant aggregate, no input
            ->pluck('total', 'stage');

        return array_map(fn (BlueprintStatus $status): array => [
            'key' => $status->value,
            'label' => $status->label(),
            'count' => (int) ($counts[$status->value] ?? 0),
        ], BlueprintStatus::cases());
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Examination>
     */
    private function query(User $user, int $branchId, array $filters): Builder
    {
        $query = Examination::query()->where('exm_examinations.branch_id', $branchId);

        $allowed = $this->courseScope->courseIds($user, $branchId);
        if ($allowed !== null) {
            $query->whereIn('exm_examinations.course_id', $allowed === [] ? [0] : $allowed);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $inner) => $inner->where('exm_examinations.title', 'like', $like)->orWhere('exm_examinations.public_ref', 'like', $like));
        }
        if (($filters['programme_id'] ?? null) !== null) {
            $query->where('exm_examinations.programme_id', (int) $filters['programme_id']);
        }
        if (($filters['year'] ?? null) !== null && preg_match('/^(\d+)(?:-(\d+))?$/', (string) $filters['year'], $match) === 1) {
            $query->where('exm_examinations.professional_id', (int) $match[1]);
            isset($match[2]) ? $query->where('exm_examinations.term_id', (int) $match[2]) : $query->whereNull('exm_examinations.term_id');
        }
        if (($filters['exam_type_id'] ?? null) !== null) {
            $query->where('exm_examinations.exam_type_id', (int) $filters['exam_type_id']);
        }
        if (($filters['course_id'] ?? null) !== null) {
            $query->where('exm_examinations.course_id', (int) $filters['course_id']);
        }
        if (($filters['stage'] ?? null) !== null && BlueprintStatus::tryFrom((string) $filters['stage']) !== null) {
            $query->whereHas('blueprint', fn (Builder $inner) => $inner->where('status', (string) $filters['stage']));
        }

        return $query;
    }
}
