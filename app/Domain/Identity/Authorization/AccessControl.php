<?php

namespace App\Domain\Identity\Authorization;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Answers "may this user do X, and where?" (blueprint section 6). Everything is managed in kmu-cms
 * and read here through the read-only v_cms_* views:
 *
 * - Permissions: CMS roles ticked in Roles → Assign Permission → Question Bank & Exams (see
 *   Permissions). A role marked Super Admin has every permission.
 * - Campuses (branches) are the outer limit, with the kmu-cms rule: a Super Admin works in every
 *   active campus; other staff in their extra campuses (staff_accessible_branches), or else in their
 *   own campus. An inactive campus gives no access.
 * - Exam access limits (CMS Question Bank & Exams → Exam Access): with none, a user works everywhere
 *   in their campuses; with some, only in those programmes, professionals and courses. A programme
 *   covers its professionals and courses.
 *
 * Results are cached for the current request (the service is registered as scoped).
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
        if (! $user->is_active) {
            return [];
        }

        return $this->isSuperAdmin($user) ? Permissions::codes() : $this->load($user)['permissions'];
    }

    public function has(User $user, string $permission): bool
    {
        return in_array($permission, $this->permissions($user), true);
    }

    /**
     * Exam access limits; empty means everywhere in the user's campuses.
     *
     * @return list<UserScope>
     */
    public function scopes(User $user): array
    {
        return $this->load($user)['scopes'];
    }

    /**
     * The campuses the user may work in. Lists and searches must filter by these.
     *
     * @return list<int>
     */
    public function branchIds(User $user): array
    {
        return $user->is_active ? $this->load($user)['branches'] : [];
    }

    public function canAccessBranch(User $user, int $branchId): bool
    {
        return in_array($branchId, $this->branchIds($user), true);
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

    /**
     * Whether the permission applies to the given place. A null target means "somewhere": use it
     * only for screens that then list just the user's campuses and limits.
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

        $scopes = $this->scopes($user);
        if ($scopes === [] || $this->isSuperAdmin($user)) {
            return true;
        }

        foreach ($scopes as $scope) {
            $matches = match ($scope->type) {
                'programme' => $scope->id === $target->programmeId,
                'professional' => $scope->id === $target->professionalId,
                'course' => $scope->id === $target->courseId,
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
        if (isset($this->cache[$user->id])) {
            return $this->cache[$user->id];
        }

        $cms = DB::connection('cms');
        $staffId = $user->cms_staff_id;

        $roles = $staffId === null ? [] : $cms->table('v_cms_staff_roles')
            ->where('staff_id', $staffId)
            ->get(['role_id', 'role_name', 'is_superadmin'])
            ->map(fn (stdClass $row): CmsRole => new CmsRole((int) $row->role_id, (string) $row->role_name, (int) $row->is_superadmin === 1))
            ->all();

        $ticked = [];
        if ($roles !== []) {
            $grants = $cms->table('v_cms_role_permissions')
                ->whereIn('role_id', array_map(fn (CmsRole $role): int => $role->id, $roles))
                ->get(['category', 'can_view', 'can_add', 'can_edit', 'can_delete']);
            foreach ($grants as $grant) {
                $current = $ticked[(string) $grant->category] ?? ['view' => false, 'add' => false, 'edit' => false, 'delete' => false];
                $ticked[(string) $grant->category] = [
                    'view' => $current['view'] || (int) $grant->can_view === 1,
                    'add' => $current['add'] || (int) $grant->can_add === 1,
                    'edit' => $current['edit'] || (int) $grant->can_edit === 1,
                    'delete' => $current['delete'] || (int) $grant->can_delete === 1,
                ];
            }
        }

        $scopes = $staffId === null ? [] : $cms->table('v_cms_staff_exam_scopes')
            ->where('staff_id', $staffId)
            ->orderBy('scope_type')->orderBy('scope_id')
            ->get(['scope_type', 'scope_id'])
            ->map(fn (stdClass $row): UserScope => new UserScope((string) $row->scope_type, (int) $row->scope_id))
            ->all();

        $loaded = [
            'roles' => array_values($roles),
            'permissions' => Permissions::fromCmsGrants($ticked),
            'scopes' => array_values($scopes),
            'branches' => $this->loadBranchIds($user, $roles),
        ];

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
        if (array_filter($roles, fn (CmsRole $role): bool => $role->isSuperAdmin) !== []) {
            return $this->activeBranchIds();
        }
        if ($user->cms_staff_id === null) {
            return [];
        }

        $cms = DB::connection('cms');
        $active = $this->activeBranchIds();

        $extra = $cms->table('v_cms_staff_branches')->where('staff_id', $user->cms_staff_id)->orderBy('branch_id')->pluck('branch_id')
            ->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        if ($extra !== []) {
            return array_values(array_intersect($extra, $active));
        }

        $own = $cms->table('v_cms_staff')->where('id', $user->cms_staff_id)->value('branch_id');

        return $own !== null && in_array((int) $own, $active, true) ? [(int) $own] : [];
    }
}
