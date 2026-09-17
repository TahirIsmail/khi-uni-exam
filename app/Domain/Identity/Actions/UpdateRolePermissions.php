<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\Permissions;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets the permissions granted to one kmu-cms role.
 *
 * Roles are shared by every campus, so only someone who works in every active branch may change
 * them. Nobody can grant or revoke a permission they do not hold themselves, and the Super Admin
 * role is never edited here (it always has everything).
 */
final class UpdateRolePermissions
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $permissions  the complete new set
     */
    public function __invoke(User $actor, int $roleId, array $permissions, ?string $reason): void
    {
        if (! $this->access->has($actor, 'admin.roles.manage') || ! $this->access->coversAllBranches($actor)) {
            throw new AuthorizationException('Changing role permissions requires access to every active branch.');
        }

        $role = DB::connection('cms')->table('v_cms_roles')->where('id', $roleId)->first(['id', 'name', 'is_superadmin']);
        if ($role === null) {
            throw ValidationException::withMessages(['role' => 'This role does not exist in kmu-cms.']);
        }
        if ((int) $role->is_superadmin === 1) {
            throw ValidationException::withMessages(['role' => 'The Super Admin role always has every permission.']);
        }

        $unknown = array_diff($permissions, Permissions::codes());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['permissions' => 'Unknown permission: '.implode(', ', $unknown)]);
        }

        DB::transaction(function () use ($actor, $roleId, $permissions, $reason, $role): void {
            $current = DB::table('sec_role_permissions')->where('cms_role_id', $roleId)->lockForUpdate()->pluck('permission_code')->map(fn (mixed $c): string => (string) $c)->all();
            $new = array_values(array_unique($permissions));
            $added = array_values(array_diff($new, $current));
            $removed = array_values(array_diff($current, $new));

            if ($added === [] && $removed === []) {
                return;
            }

            $mine = $this->access->permissions($actor);
            $beyondActor = array_diff([...$added, ...$removed], $mine);
            if ($beyondActor !== []) {
                throw new AuthorizationException('You cannot grant or revoke permissions you do not hold: '.implode(', ', $beyondActor));
            }
            if ($removed !== [] && in_array($actor->cms_staff_id, $this->holdersOf($roleId), true) && in_array('admin.roles.manage', $removed, true)) {
                throw ValidationException::withMessages(['permissions' => 'You cannot remove role management from a role you hold yourself.']);
            }

            if ($removed !== []) {
                DB::table('sec_role_permissions')->where('cms_role_id', $roleId)->whereIn('permission_code', $removed)->delete();
            }
            $now = now();
            DB::table('sec_role_permissions')->insert(array_map(fn (string $code): array => [
                'cms_role_id' => $roleId,
                'permission_code' => $code,
                'granted_by' => $actor->id,
                'granted_at' => $now,
            ], $added));

            sort($current);
            sort($new);
            $this->audit->record(
                'admin.role_permissions.changed',
                'cms_role',
                $roleId,
                ['role' => (string) $role->name, 'permissions' => $current],
                ['role' => (string) $role->name, 'permissions' => $new, 'added' => $added, 'removed' => $removed],
                $reason,
                $actor,
            );
        });
    }

    /**
     * @return list<int> CMS staff ids holding the role
     */
    private function holdersOf(int $roleId): array
    {
        return array_values(DB::connection('cms')->table('v_cms_staff_roles')->where('role_id', $roleId)->pluck('staff_id')->map(fn (mixed $id): int => (int) $id)->all());
    }
}
