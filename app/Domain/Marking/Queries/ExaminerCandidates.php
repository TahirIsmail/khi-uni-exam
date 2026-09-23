<?php

namespace App\Domain\Marking\Queries;

use App\Domain\Exam\Models\Examination;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Staff who could be assigned as an examiner: those of the examination's campus whose CMS role
 * ticks "Mark Manually-Marked Items" (or a Super Admin), the same way BlueprintApprovers finds who
 * could approve a blueprint.
 */
final class ExaminerCandidates
{
    /**
     * @return list<array{id: int, name: string}>
     */
    public function forExamination(Examination $examination): array
    {
        $holders = DB::connection('cms')->table('v_cms_staff_roles as sr')
            ->leftJoin('v_cms_role_permissions as rp', function ($join): void {
                $join->on('rp.role_id', '=', 'sr.role_id')->where('rp.category', '=', 'exam_marking')->where('rp.can_view', '=', 1);
            })
            ->where(fn ($inner) => $inner->where('sr.is_superadmin', 1)->orWhereNotNull('rp.role_id'))
            ->pluck('sr.staff_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();

        $inCampus = DB::connection('cms')->table('v_cms_staff_branches')->where('branch_id', $examination->branch_id)
            ->pluck('staff_id')->map(fn (mixed $id): int => (int) $id)->all();
        $staffIds = array_values(array_intersect($holders, array_merge($inCampus, DB::connection('cms')->table('v_cms_staff')->where('branch_id', $examination->branch_id)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all())));

        if ($staffIds === []) {
            return [];
        }

        $users = User::query()->whereIn('cms_staff_id', $staffIds)->where('is_active', true)->get(['id', 'cms_staff_id']);
        $names = DB::connection('cms')->table('v_cms_staff')->whereIn('id', $staffIds)->get(['id', 'name', 'surname'])->keyBy('id');

        return array_values($users->map(fn (User $user): array => [
            'id' => $user->id,
            'name' => trim(($names->get($user->cms_staff_id)->name ?? '').' '.($names->get($user->cms_staff_id)->surname ?? '')),
        ])->sortBy('name')->values()->all());
    }
}
