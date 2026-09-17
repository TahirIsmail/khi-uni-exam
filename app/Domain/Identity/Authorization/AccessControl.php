<?php

namespace App\Domain\Identity\Authorization;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Answers "may this user do X, and where?" (blueprint section 6).
 *
 * - Roles come from kmu-cms (v_cms_staff_roles); permissions are granted to those roles here.
 * - A CMS Super Admin role, or a local break-glass account, has every permission everywhere.
 * - Scopes limit where a permission applies: everywhere ("all"), or specific programmes,
 *   professionals or courses. A programme scope covers its professionals and courses.
 *
 * Results are cached for the current request (the service is registered as scoped).
 */
final class AccessControl
{
    /** @var array<int, array{roles: list<CmsRole>, permissions: list<string>, scopes: list<UserScope>}> */
    private array $cache = [];

    /**
     * @return list<CmsRole>
     */
    public function roles(User $user): array
    {
        return $this->load($user)['roles'];
    }

    public function isSuperAdmin(User $user): bool
    {
        if ($user->is_break_glass) {
            return true;
        }

        foreach ($this->roles($user) as $role) {
            if ($role->isSuperAdmin) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function permissions(User $user): array
    {
        return $this->isSuperAdmin($user) ? Permissions::codes() : $this->load($user)['permissions'];
    }

    public function has(User $user, string $permission): bool
    {
        return $user->is_active && in_array($permission, $this->permissions($user), true);
    }

    /** Whether the user must use multi-factor authentication. */
    public function isPrivileged(User $user): bool
    {
        return $this->isSuperAdmin($user) || array_intersect(Permissions::PRIVILEGED, $this->permissions($user)) !== [];
    }

    /**
     * @return list<UserScope>
     */
    public function scopes(User $user): array
    {
        return $this->load($user)['scopes'];
    }

    /**
     * Whether the permission applies to the given place. A null target means "anywhere at all"
     * (for screens that list only what the user may see).
     */
    public function allows(User $user, string $permission, ?ScopeTarget $target = null): bool
    {
        if (! $this->has($user, $permission)) {
            return false;
        }
        if ($target === null || $this->isSuperAdmin($user)) {
            return true;
        }

        foreach ($this->scopes($user) as $scope) {
            $matches = match ($scope->type) {
                'all' => true,
                'programme' => $target->programmeId !== null && $scope->id === $target->programmeId,
                'professional' => $target->professionalId !== null && $scope->id === $target->professionalId,
                'course' => $target->courseId !== null && $scope->id === $target->courseId,
                default => false,
            };
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    public function forget(User $user): void
    {
        unset($this->cache[$user->id]);
    }

    /**
     * @return array{roles: list<CmsRole>, permissions: list<string>, scopes: list<UserScope>}
     */
    private function load(User $user): array
    {
        if (isset($this->cache[$user->id])) {
            return $this->cache[$user->id];
        }

        $roles = $user->cms_staff_id === null ? [] : DB::connection('cms')
            ->table('v_cms_staff_roles')
            ->where('staff_id', $user->cms_staff_id)
            ->get(['role_id', 'role_name', 'is_superadmin'])
            ->map(fn (object $row): CmsRole => new CmsRole((int) $row->role_id, (string) $row->role_name, (int) $row->is_superadmin === 1))
            ->all();

        $permissions = $roles === [] ? [] : DB::table('sec_role_permissions')
            ->whereIn('cms_role_id', array_map(fn (CmsRole $role): int => $role->id, $roles))
            ->distinct()
            ->pluck('permission_code')
            ->map(fn (mixed $code): string => (string) $code)
            ->all();

        $scopes = DB::table('sec_user_scopes')
            ->where('user_id', $user->id)
            ->get(['scope_type', 'scope_id'])
            ->map(fn (object $row): UserScope => new UserScope((string) $row->scope_type, $row->scope_id === null ? null : (int) $row->scope_id))
            ->all();

        return $this->cache[$user->id] = ['roles' => array_values($roles), 'permissions' => array_values($permissions), 'scopes' => array_values($scopes)];
    }
}
