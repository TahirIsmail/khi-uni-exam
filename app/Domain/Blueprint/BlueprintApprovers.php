<?php

namespace App\Domain\Blueprint;

use App\Domain\Blueprint\Models\Blueprint;
use App\Domain\Exam\Models\Examination;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Who could approve a blueprint: the staff of its campus whose CMS role ticks "Approve Blueprints"
 * (and Super Admins), leaving out the people who wrote or submitted it, because nobody approves their
 * own. They are read from kmu-cms, so people who have not opened the module yet are included: the
 * list is there to tell the person waiting whom to ask.
 */
final class BlueprintApprovers
{
    /**
     * @return list<string> names, in alphabetical order
     */
    public function names(Examination $examination, Blueprint $blueprint): array
    {
        $own = User::query()->whereIn('id', array_filter([$blueprint->created_by, $blueprint->submitted_by]))->pluck('cms_staff_id')->filter()->map(fn (mixed $id): int => (int) $id)->all();

        $holders = DB::connection('cms')->table('v_cms_staff_roles as sr')
            ->leftJoin('v_cms_role_permissions as rp', function ($join): void {
                $join->on('rp.role_id', '=', 'sr.role_id')->where('rp.category', '=', 'exam_blueprints_approve')->where('rp.can_view', '=', 1);
            })
            ->where(fn (Builder $inner) => $inner->where('sr.is_superadmin', 1)->orWhereNotNull('rp.role_id'))
            ->get(['sr.staff_id', 'sr.is_superadmin']);

        $superAdmins = $holders->where('is_superadmin', 1)->pluck('staff_id')->map(fn (mixed $id): int => (int) $id)->all();
        $ids = $holders->pluck('staff_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();

        $staff = DB::connection('cms')->table('v_cms_staff as s')
            ->whereIn('s.id', $ids === [] ? [0] : $ids)
            ->whereNotIn('s.id', $own === [] ? [0] : $own)
            ->where('s.is_active', 1)
            ->orderBy('s.name')->orderBy('s.surname')
            ->get(['s.id', 's.name', 's.surname', 's.branch_id']);

        $inCampus = DB::connection('cms')->table('v_cms_staff_branches')->where('branch_id', $examination->branch_id)->pluck('staff_id')->map(fn (mixed $id): int => (int) $id)->all();

        return array_values($staff
            ->filter(fn (stdClass $row): bool => in_array((int) $row->id, $superAdmins, true)
                || (int) $row->branch_id === $examination->branch_id
                || in_array((int) $row->id, $inCampus, true))
            ->map(fn (stdClass $row): string => trim($row->name.' '.$row->surname))
            ->all());
    }
}
