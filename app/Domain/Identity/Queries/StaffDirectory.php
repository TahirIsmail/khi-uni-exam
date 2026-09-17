<?php

namespace App\Domain\Identity\Queries;

use App\Domain\Identity\Authorization\AccessControl;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Active kmu-cms staff in the administrator's branches, for the user scopes screen.
 */
final class StaffDirectory
{
    public function __construct(private readonly AccessControl $access) {}

    /**
     * @return LengthAwarePaginator<int, array{staffId: int, employeeId: string, name: string, email: string, branch: string|null, signedIn: bool, lastLoginAt: string|null, scopeCount: int, isActive: bool}>
     */
    public function search(User $actor, ?string $term, int $perPage = 25): LengthAwarePaginator
    {
        $cms = DB::connection('cms');
        $branches = $this->access->branchIds($actor);
        $everyBranch = $this->access->coversAllBranches($actor);

        $query = $cms->table('v_cms_staff as s')
            ->leftJoin('v_cms_branches as b', 'b.id', '=', 's.branch_id')
            ->where('s.is_active', 1)
            ->where(function (Builder $q) use ($branches, $everyBranch): void {
                $q->whereIn('s.branch_id', $branches === [] ? [0] : $branches)
                    ->orWhereIn('s.id', fn (Builder $sub) => $sub->from('v_cms_staff_branches')->select('staff_id')->whereIn('branch_id', $branches === [] ? [0] : $branches));
                if ($everyBranch) {
                    // Staff with no branch at all (for example the CMS Super Admin) are listed only for administrators of every branch.
                    $q->orWhereNull('s.branch_id');
                }
            });

        $term = trim((string) $term);
        if ($term !== '') {
            $like = '%'.addcslashes($term, '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('s.name', 'like', $like)
                ->orWhere('s.surname', 'like', $like)
                ->orWhere('s.email', 'like', $like)
                ->orWhere('s.employee_id', 'like', $like));
        }

        $page = $query->orderBy('s.name')->orderBy('s.surname')->orderBy('s.id')
            ->paginate($perPage, ['s.id', 's.employee_id', 's.name', 's.surname', 's.email', 'b.branch_name'])
            ->withQueryString();

        $staffIds = array_map(fn (stdClass $row): int => (int) $row->id, $page->items());
        $users = User::query()->whereIn('cms_staff_id', $staffIds)->withCount('scopes')->get()->keyBy('cms_staff_id');

        return $page->through(function (stdClass $row) use ($users): array {
            /** @var User|null $user */
            $user = $users->get((int) $row->id);

            return [
                'staffId' => (int) $row->id,
                'employeeId' => (string) $row->employee_id,
                'name' => trim($row->name.' '.$row->surname),
                'email' => (string) $row->email,
                'branch' => $row->branch_name === null ? null : (string) $row->branch_name,
                'signedIn' => $user?->last_login_at !== null,
                'lastLoginAt' => $user?->last_login_at?->toIso8601String(),
                'scopeCount' => (int) ($user->scopes_count ?? 0),
                'isActive' => $user === null || $user->is_active,
            ];
        });
    }
}
