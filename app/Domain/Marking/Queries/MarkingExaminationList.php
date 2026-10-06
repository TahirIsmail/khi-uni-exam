<?php

namespace App\Domain\Marking\Queries;

use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Exam\Models\Examination;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Marking\Models\ExaminerAssignment;
use App\Models\User;
use App\Support\Cms\CmsAcademic;
use App\Support\Cms\CmsTeaching;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Examinations with something submitted that this person has any business marking — the starting
 * list for the Marking screen.
 *
 * Who sees an examination here:
 *
 * - a Super Admin, and whoever appoints examiners (`marking.assign`), see the whole campus: they
 *   cannot appoint an examiner to an examination they are not allowed to look at;
 * - an appointed examiner sees the examinations they were appointed to, wherever they are;
 * - a teacher sees the examinations of the programmes and intakes they are assigned to teach in
 *   kmu-cms (Academics → Assign Program Teacher).
 *
 * Seeing an examination is not permission to mark it: that still needs the appointment recorded by
 * App\Domain\Marking\Actions\AssignExaminer, which App\Domain\Marking\Actions\RecordExaminerMark
 * insists on.
 */
final class MarkingExaminationList
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly CmsTeaching $teaching,
        private readonly CmsAcademic $academic,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forBranch(int $branchId, User $user): array
    {
        $examIds = DB::table('cand_candidate_exams')->where('cand_candidate_exams.status', AttemptStatus::Submitted->value)
            ->join('exm_examinations', 'exm_examinations.id', '=', 'cand_candidate_exams.examination_id')
            ->where('exm_examinations.branch_id', $branchId)
            ->distinct()->pluck('cand_candidate_exams.examination_id');

        $examinations = $this->visibleTo($user, Examination::query()->whereIn('id', $examIds)->orderByDesc('id')->get());

        if ($examinations->isEmpty()) {
            return [];
        }

        $visibleIds = $examinations->pluck('id');

        $submittedCounts = DB::table('cand_candidate_exams')->whereIn('examination_id', $visibleIds)
            ->where('cand_candidate_exams.status', AttemptStatus::Submitted->value)
            ->selectRaw('examination_id, count(*) as total') // raw-sql-reviewed: no user input, plain aggregate
            ->groupBy('examination_id')->pluck('total', 'examination_id');

        $programmes = collect($this->academic->programmes($branchId))->pluck('name', 'id');
        $intakes = collect($this->academic->intakes($branchId))->pluck('name', 'id');
        $courses = $this->academic->courseLabels($branchId);

        return array_values($examinations->map(fn (Examination $exam): array => [
            'id' => $exam->id,
            'reference' => $exam->public_ref,
            'title' => $exam->title,
            'requireDoubleMarking' => $exam->require_double_marking,
            'submittedCount' => (int) ($submittedCounts[$exam->id] ?? 0),
            'programme' => $programmes->get($exam->programme_id),
            'year' => $this->academic->yearName($branchId, $exam->professional_id, $exam->term_id),
            'intake' => $exam->intake_id === null ? null : $intakes->get($exam->intake_id),
            'course' => $courses[$exam->course_id] ?? null,
        ])->all());
    }

    /**
     * Whether this person has any teaching assignment at all — the screen says so plainly rather
     * than showing an empty table with no explanation.
     */
    public function isScopedByTeaching(User $user): bool
    {
        return ! $this->access->isSuperAdmin($user) && ! $this->access->has($user, 'marking.assign');
    }

    public function hasTeachingAssignments(User $user): bool
    {
        return $this->teaching->hasAnyAssignment($user);
    }

    /**
     * Whether one examination belongs on this person's Marking screen — the same rule the list
     * applies, asked about a single examination so opening one by its address is guarded too.
     */
    public function maySee(User $user, Examination $examination): bool
    {
        if (! $this->isScopedByTeaching($user)) {
            return true;
        }

        if ($this->teaching->teaches($user, $examination->programme_id, $examination->intake_id)) {
            return true;
        }

        return ExaminerAssignment::query()
            ->where('examination_id', $examination->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * @param  Collection<int, Examination>  $examinations
     * @return Collection<int, Examination>
     */
    private function visibleTo(User $user, Collection $examinations): Collection
    {
        if (! $this->isScopedByTeaching($user)) {
            return $examinations;
        }

        $appointed = ExaminerAssignment::query()
            ->whereIn('examination_id', $examinations->pluck('id'))
            ->where('user_id', $user->id)
            ->pluck('examination_id')
            ->all();

        return $examinations->filter(fn (Examination $exam): bool => in_array($exam->id, $appointed, true)
            || $this->teaching->teaches($user, $exam->programme_id, $exam->intake_id));
    }
}
