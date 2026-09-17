<?php

namespace App\Domain\Identity\Authorization;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Answers "may this user do X, and where?" (blueprint section 6).
 *
 * - Roles come from kmu-cms (v_cms_staff_roles); permissions are granted to those roles here.
 * - A CMS Super Admin role, or a local break-glass account, has every permission everywhere.
 * - Branches (campuses) are the outer limit, using the kmu-cms rule: a Super Admin works in every
 *   active branch; other staff in their extra branches (staff_accessible_branches), or else in
 *   their own branch. Unlike kmu-cms, an inactive own branch gives no access.
 * - Scopes limit where a permission applies inside those branches: everywhere ("all"), or specific
 *   programmes, professionals or courses. A programme scope covers its professionals and courses.
 *
 * Results are cached for the current request (the service is registered as scoped). An unsaved User
 * with cms_staff_id set can be checked too, for staff who have not signed in yet.
 */
final class AccessControl
{
    /** @var array<int, array{roles: list<CmsRole>, permissions: list<string>, scopes: list<UserScope>, branches: list<int>}> */
    private array $cache = [];

    /** @var list<int>|null */
    private ?array $activeBranches = null;

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
     * The branches (campuses) the user may work in. Lists and searches must filter by these.
     *
     * @return list<int>
     */
    public function branchIds(User $user): array
    {
        return $user->is_active ? $this->load($user)['branches'] : [];
    }

    /**
     * Whether the permission applies everywhere in the given branches: the user works in all of them
     * and is a Super Admin or has an "all" scope.
     *
     * @param  list<int>  $branchIds
     */
    public function allowsEverywhereIn(User $user, string $permission, array $branchIds): bool
    {
        if (! $this->has($user, $permission) || array_diff($branchIds, $this->branchIds($user)) !== []) {
            return false;
        }

        return $this->isSuperAdmin($user) || array_filter($this->scopes($user), fn (UserScope $scope): bool => $scope->type === 'all') !== [];
    }

    /**
     * Whether the user works in every active branch. Settings shared by all campuses (role grants)
     * and audit entries that belong to no single campus are limited to these users.
     */
    public function coversAllBranches(User $user): bool
    {
        $mine = $this->branchIds($user);
        $active = $this->activeBranchIds();

        return $mine !== [] && array_diff($active, $mine) === [];
    }

    /**
     * @return list<int>
     */
    public function activeBranchIds(): array
    {
        return $this->activeBranches ??= array_values(array_map(
            fn (mixed $id): int => (int) $id,
            DB::connection('cms')->table('v_cms_branches')->where('status', 'active')->orderBy('id')->pluck('id')->all(),
        ));
    }

    public function canAccessBranch(User $user, int $branchId): bool
    {
        return in_array($branchId, $this->branchIds($user), true);
    }

    /**
     * Whether the permission applies to the given place. A null target means "somewhere": use it
     * only for screens that then list just the user's branches and scopes.
     */
    public function allows(User $user, string $permission, ?ScopeTarget $target = null): bool
    {
        if (! $this->has($user, $permission)) {
            return false;
        }
        if ($target === null) {
            return true;
        }
        if (! $this->canAccessBranch($user, $target->branchId)) {
            return false;
        }
        if ($this->isSuperAdmin($user)) {
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
        $this->activeBranches = null;
    }

    /**
     * @return array{roles: list<CmsRole>, permissions: list<string>, scopes: list<UserScope>, branches: list<int>}
     */
    private function load(User $user): array
    {
        if ($user->exists && isset($this->cache[$user->id])) {
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

        $scopes = ! $user->exists ? [] : DB::table('sec_user_scopes')
            ->where('user_id', $user->id)
            ->get(['scope_type', 'scope_id'])
            ->map(fn (object $row): UserScope => new UserScope((string) $row->scope_type, $row->scope_id === null ? null : (int) $row->scope_id))
            ->all();

        $loaded = [
            'roles' => array_values($roles),
            'permissions' => array_values($permissions),
            'scopes' => array_values($scopes),
            'branches' => $this->loadBranchIds($user, $roles),
        ];

        // Unsaved users (staff who have not signed in yet) are checked but never cached by id.
        if ($user->exists) {
            $this->cache[$user->id] = $loaded;
        }

        return $loaded;
    }

    /**
     * @param  array<int, CmsRole>  $roles
     * @return list<int>
     */
    private function loadBranchIds(User $user, array $roles): array
    {
        $cms = DB::connection('cms');
        $activeBranches = fn () => $cms->table('v_cms_branches')->where('status', 'active');
        $ids = fn (iterable $values): array => array_values(array_unique(array_map(fn (mixed $id): int => (int) $id, [...$values])));

        $superAdmin = $user->is_break_glass || array_filter($roles, fn (CmsRole $role): bool => $role->isSuperAdmin) !== [];
        if ($superAdmin) {
            return $this->activeBranchIds();
        }
        if ($user->cms_staff_id === null) {
            return [];
        }

        $extra = $ids($cms->table('v_cms_staff_branches')->where('staff_id', $user->cms_staff_id)->orderBy('branch_id')->pluck('branch_id'));
        if ($extra !== []) {
            return $extra;
        }

        $own = $cms->table('v_cms_staff')->where('id', $user->cms_staff_id)->value('branch_id');

        return $own !== null && $activeBranches()->where('id', $own)->exists() ? [(int) $own] : [];
    }
}
