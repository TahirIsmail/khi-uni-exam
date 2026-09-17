<?php

namespace App\Domain\Identity\Queries;

use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\Identity\Models\UserScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Data for one staff member's scopes screen: their roles, branches, current scopes with readable
 * names, and the places the administrator may add (only in branches both of them work in).
 */
final class StaffScopeDetails
{
    public function __construct(private readonly AccessControl $access) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $actor, User $subject): array
    {
        $cms = DB::connection('cms');
        $subjectBranches = $this->access->branchIds($subject);
        $shared = array_values(array_intersect($subjectBranches, $this->access->branchIds($actor)));
        $branchNames = $cms->table('v_cms_branches')->whereIn('id', $subjectBranches === [] ? [0] : $subjectBranches)->pluck('branch_name', 'id');

        $programmes = $cms->table('v_cms_programmes')->whereIn('branch_id', $shared === [] ? [0] : $shared)->orderBy('name')->get(['id', 'branch_id', 'name', 'code']);
        $professionals = $cms->table('v_cms_professionals')->whereIn('branch_id', $shared === [] ? [0] : $shared)->orderBy('programme_id')->orderBy('sequence')->get(['id', 'branch_id', 'programme_id', 'code', 'name']);
        $courses = $cms->table('v_cms_courses')->whereIn('branch_id', $shared === [] ? [0] : $shared)->where('status', 'active')->orderBy('course_code')->get(['id', 'branch_id', 'programme_id', 'course_code', 'title']);

        $programmeNames = $programmes->mapWithKeys(fn (stdClass $p): array => [(int) $p->id => "{$p->name} ({$p->code})"]);
        $option = fn (string $type, stdClass $row, string $label): array => [
            'type' => $type,
            'id' => (int) $row->id,
            'label' => $label,
            'allowed' => $this->access->allows($actor, 'admin.users.manage', match ($type) {
                'programme' => new ScopeTarget((int) $row->branch_id, (int) $row->id),
                'professional' => new ScopeTarget((int) $row->branch_id, (int) $row->programme_id, (int) $row->id),
                default => new ScopeTarget((int) $row->branch_id, (int) $row->programme_id, null, (int) $row->id),
            }),
        ];

        $options = [
            ...$programmes->map(fn (stdClass $p): array => $option('programme', $p, "{$p->name} ({$p->code})")),
            ...$professionals->map(fn (stdClass $p): array => $option('professional', $p, ($programmeNames[(int) $p->programme_id] ?? '').' — '.$p->name)),
            ...$courses->map(fn (stdClass $c): array => $option('course', $c, "{$c->course_code} — {$c->title}")),
        ];

        $scopes = ! $subject->exists ? [] : UserScope::query()->where('user_id', $subject->id)->with('grantedBy:id,name')->orderBy('scope_type')->orderBy('id')->get()
            ->map(fn (UserScope $scope): array => [
                'id' => $scope->id,
                'type' => $scope->scope_type,
                'label' => $this->label($scope),
                'grantedBy' => $scope->grantedBy?->name,
                'createdAt' => $scope->created_at?->toIso8601String(),
            ])->all();

        return [
            'staff' => [
                'staffId' => (int) $subject->cms_staff_id,
                'name' => $subject->name,
                'email' => $subject->email,
                'signedIn' => $subject->exists && $subject->last_login_at !== null,
                'branches' => array_map(fn (int $id): string => (string) ($branchNames[$id] ?? "#{$id}"), $subjectBranches),
                'roles' => array_map(fn ($role): string => $role->name, $this->access->roles($subject)),
                'isSuperAdmin' => $this->access->isSuperAdmin($subject),
                'permissionCount' => count($this->access->permissions($subject)),
            ],
            'scopes' => $scopes,
            'options' => array_values(array_filter($options, fn (array $o): bool => $o['allowed'])),
            'canAddAll' => $this->access->allowsEverywhereIn($actor, 'admin.users.manage', $subjectBranches)
                && ($subjectBranches !== [] || $this->access->coversAllBranches($actor)),
        ];
    }

    private function label(UserScope $scope): string
    {
        $cms = DB::connection('cms');

        return match ($scope->scope_type) {
            'all' => 'Everywhere in their branches',
            'programme' => ($p = $cms->table('v_cms_programmes')->where('id', $scope->scope_id)->first(['name', 'code'])) ? "Programme: {$p->name} ({$p->code})" : "Programme #{$scope->scope_id} (no longer in kmu-cms)",
            'professional' => ($p = $cms->table('v_cms_professionals')->where('id', $scope->scope_id)->first(['name'])) ? "Professional: {$p->name}" : "Professional #{$scope->scope_id} (no longer in kmu-cms)",
            default => ($c = $cms->table('v_cms_courses')->where('id', $scope->scope_id)->first(['course_code', 'title'])) ? "Course: {$c->course_code} — {$c->title}" : "Course #{$scope->scope_id} (no longer in kmu-cms)",
        };
    }
}
