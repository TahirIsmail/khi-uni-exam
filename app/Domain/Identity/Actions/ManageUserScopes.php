<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\Identity\Exceptions\StaffEmailConflict;
use App\Domain\Identity\Models\CmsStaff;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds and removes where a staff member's permissions apply (sec_user_scopes), branch-wise:
 *
 * - the administrator must share a branch with the staff member, and may not edit themselves;
 * - a programme, professional or course scope must be in a branch of the staff member, and the
 *   administrator's own permission must reach it (their branches and scopes);
 * - an "all" scope covers every branch of the staff member, so the administrator must work in all
 *   of those branches.
 */
final class ManageUserScopes
{
    public const TYPES = ['all', 'programme', 'professional', 'course'];

    public function __construct(
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
        private readonly LinkCmsStaff $link,
    ) {}

    /**
     * The staff member's local user. If they have not signed in yet this is an unsaved user, unless
     * $create is set (only when a scope is being added). Throws unless the actor may manage them.
     */
    public function subject(User $actor, int $cmsStaffId, bool $create = false): User
    {
        if (! $this->access->has($actor, 'admin.users.manage')) {
            throw new AuthorizationException;
        }

        $staff = CmsStaff::query()->find($cmsStaffId);
        if ($staff === null || ! $staff->is_active) {
            throw new AuthorizationException('Unknown or inactive staff member.');
        }

        $subject = User::query()->where('cms_staff_id', $cmsStaffId)->first();
        if ($subject === null) {
            $subject = new User;
            $subject->forceFill(['cms_staff_id' => $staff->id, 'name' => $staff->fullName(), 'email' => $staff->email, 'is_active' => true]);
        }

        if ((int) $subject->cms_staff_id === (int) $actor->cms_staff_id || $subject->id === $actor->id) {
            throw new AuthorizationException('You cannot change your own scopes.');
        }
        if (! $this->sharesBranch($actor, $subject)) {
            throw new AuthorizationException('This staff member is not in any of your branches.');
        }

        if (! $subject->is_active) {
            throw ValidationException::withMessages(['staff' => 'This account is deactivated in KMU Assessment.']);
        }

        return $create && ! $subject->exists ? $this->linkOrFail($staff) : $subject;
    }

    public function sharesBranch(User $actor, User $subject): bool
    {
        $theirs = $this->access->branchIds($subject);

        return $theirs === []
            ? $this->access->coversAllBranches($actor)
            : array_intersect($theirs, $this->access->branchIds($actor)) !== [];
    }

    public function add(User $actor, int $cmsStaffId, string $type, ?int $scopeId): void
    {
        $branchId = $this->authoriseScope($actor, $this->subject($actor, $cmsStaffId), $type, $scopeId);
        $subject = $this->subject($actor, $cmsStaffId, create: true);

        DB::transaction(function () use ($actor, $subject, $type, $scopeId, $branchId): void {
            try {
                DB::table('sec_user_scopes')->insert([
                    'user_id' => $subject->id,
                    'scope_type' => $type,
                    'scope_id' => $type === 'all' ? null : $scopeId,
                    'granted_by' => $actor->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['scope_id' => 'This staff member already has that scope.']);
            }

            $this->audit->record('admin.user_scope.added', 'user', $subject->id, null, $this->describe($type, $scopeId), null, $actor, $branchId);
        });
        $this->access->forget($subject);
    }

    public function remove(User $actor, int $cmsStaffId, int $userScopeId): void
    {
        $subject = $this->subject($actor, $cmsStaffId);
        $scope = DB::table('sec_user_scopes')->where('id', $userScopeId)->where('user_id', $subject->id)->first(['id', 'scope_type', 'scope_id']);
        if ($scope === null) {
            throw new AuthorizationException('Unknown scope.');
        }

        $type = (string) $scope->scope_type;
        $scopeId = $scope->scope_id === null ? null : (int) $scope->scope_id;
        $branchId = $this->authoriseScope($actor, $subject, $type, $scopeId, removing: true);

        DB::transaction(function () use ($actor, $subject, $scope, $type, $scopeId, $branchId): void {
            DB::table('sec_user_scopes')->where('id', $scope->id)->delete();
            $this->audit->record('admin.user_scope.removed', 'user', $subject->id, $this->describe($type, $scopeId), null, null, $actor, $branchId);
        });
        $this->access->forget($subject);
    }

    /**
     * @return int|null the branch the scope belongs to (null for "all")
     */
    private function authoriseScope(User $actor, User $subject, string $type, ?int $scopeId, bool $removing = false): ?int
    {
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['scope_type' => 'Unknown scope type.']);
        }

        $subjectBranches = $this->access->branchIds($subject);

        if ($type === 'all') {
            if (array_diff($subjectBranches, $this->access->branchIds($actor)) !== [] || ! $this->access->allowsEverywhereIn($actor, 'admin.users.manage', $subjectBranches)) {
                throw new AuthorizationException('An "all" scope covers every branch of this staff member; you must manage all of them.');
            }

            return null;
        }

        $target = match ($type) {
            'programme' => ScopeTarget::programme((int) $scopeId),
            'professional' => ScopeTarget::professional((int) $scopeId),
            default => ScopeTarget::course((int) $scopeId),
        };
        if ($target === null) {
            if ($removing) {
                // The CMS record is gone; any administrator who shares a branch may clean it up.
                return null;
            }
            throw ValidationException::withMessages(['scope_id' => 'This '.$type.' does not exist in kmu-cms.']);
        }
        if (! $this->access->allows($actor, 'admin.users.manage', $target)) {
            throw new AuthorizationException('That '.$type.' is outside the branches and scopes you manage.');
        }
        if (! $removing && ! in_array($target->branchId, $subjectBranches, true)) {
            throw ValidationException::withMessages(['scope_id' => 'That '.$type.' is in a branch this staff member does not work in.']);
        }

        return $target->branchId;
    }

    /**
     * @return array{scope_type: string, scope_id: int|null}
     */
    private function describe(string $type, ?int $scopeId): array
    {
        return ['scope_type' => $type, 'scope_id' => $type === 'all' ? null : $scopeId];
    }

    private function linkOrFail(CmsStaff $staff): User
    {
        try {
            return ($this->link)($staff);
        } catch (StaffEmailConflict) {
            throw ValidationException::withMessages(['staff' => 'A local account already uses this staff member\'s email. Resolve it before setting scopes.']);
        }
    }
}
