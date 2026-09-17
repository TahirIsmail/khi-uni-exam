<?php

namespace App\Domain\Identity\Queries;

use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\Permissions;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Data for the roles × permissions screen.
 */
final class RolePermissionMatrix
{
    public function __construct(private readonly AccessControl $access) {}

    /**
     * @return array{
     *     roles: list<array{id: int, name: string, isSuperAdmin: bool, staffCount: int, permissions: list<string>}>,
     *     groups: list<array{name: string, permissions: list<array{code: string, description: string, privileged: bool, grantable: bool}>}>,
     *     canEdit: bool,
     * }
     */
    public function forActor(User $actor): array
    {
        $grants = [];
        foreach (DB::table('sec_role_permissions')->orderBy('permission_code')->get(['cms_role_id', 'permission_code']) as $grant) {
            $grants[(int) $grant->cms_role_id][] = (string) $grant->permission_code;
        }

        $staffCounts = DB::connection('cms')->table('v_cms_staff_roles')
            ->join('v_cms_staff', 'v_cms_staff.id', '=', 'v_cms_staff_roles.staff_id')
            ->where('v_cms_staff.is_active', 1)
            ->groupBy('v_cms_staff_roles.role_id')
            ->select('v_cms_staff_roles.role_id')
            ->selectRaw('COUNT(*) AS staff_count') // raw-sql-reviewed: constant aggregate, no input
            ->pluck('staff_count', 'role_id');

        $roles = [];
        foreach (DB::connection('cms')->table('v_cms_roles')->orderByDesc('is_superadmin')->orderBy('name')->get(['id', 'name', 'is_superadmin']) as $role) {
            $superAdmin = (int) $role->is_superadmin === 1;
            $roles[] = [
                'id' => (int) $role->id,
                'name' => (string) $role->name,
                'isSuperAdmin' => $superAdmin,
                'staffCount' => (int) ($staffCounts[$role->id] ?? 0),
                'permissions' => $superAdmin ? Permissions::codes() : ($grants[(int) $role->id] ?? []),
            ];
        }

        $mine = $this->access->permissions($actor);
        $groups = [];
        foreach (Permissions::CATALOGUE as $group => $permissions) {
            $items = [];
            foreach ($permissions as $code => $description) {
                $items[] = [
                    'code' => $code,
                    'description' => $description,
                    'privileged' => in_array($code, Permissions::PRIVILEGED, true),
                    'grantable' => in_array($code, $mine, true),
                ];
            }
            $groups[] = ['name' => $group, 'permissions' => $items];
        }

        return [
            'roles' => $roles,
            'groups' => $groups,
            'canEdit' => $this->access->has($actor, 'admin.roles.manage') && $this->access->coversAllBranches($actor),
        ];
    }
}
