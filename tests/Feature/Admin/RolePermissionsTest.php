<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class);

beforeEach(function () {
    $this->shareCmsConnection();
    $this->branch = $this->cmsBranch('Main Campus');
});

function grantsOf(int $roleId): array
{
    return DB::table('sec_role_permissions')->where('cms_role_id', $roleId)->orderBy('permission_code')->pluck('permission_code')->all();
}

test('guests are sent to login and staff without the permission are refused', function () {
    $this->get('/admin/roles')->assertRedirect('/login');

    $user = $this->adminWith(['qbank.question.view'], $this->branch);
    $role = $this->cmsRole('Faculty');

    $this->actingAs($user)->get('/admin/roles')->assertForbidden();
    $this->actingAs($user)->put("/admin/roles/{$role}", ['permissions' => ['qbank.question.view']])->assertForbidden();
    expect(grantsOf($role))->toBe([]);
});

test('the matrix lists CMS roles, their grants and what the viewer may grant', function () {
    $admin = $this->adminWith(['admin.roles.manage', 'qbank.question.view'], $this->branch);
    $faculty = $this->cmsRole('Faculty');
    $this->grant($faculty, 'qbank.question.view');
    $this->cmsRole('Super Admin', true);

    $this->actingAs($admin)->get('/admin/roles')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/Roles')
        ->where('canEdit', true)
        ->where('roles.0.name', 'Super Admin')
        ->where('roles.0.isSuperAdmin', true)
        ->where('roles.0.permissions', fn ($codes) => count($codes) === 50)
        ->where('roles', fn ($roles) => collect($roles)->firstWhere('id', $faculty)['permissions'] === ['qbank.question.view'])
        ->where('groups.0.permissions', fn ($perms) => collect($perms)->firstWhere('code', 'qbank.question.view')['grantable'] === true
            && collect($perms)->firstWhere('code', 'qbank.question.create')['grantable'] === false));
});

test('an administrator of every branch changes a role\'s permissions, and the change is audited', function () {
    $admin = $this->staffUser([$this->cmsRole('Super Admin', true)], $this->branch);
    $faculty = $this->cmsRole('Faculty');
    $this->grant($faculty, 'qbank.question.view', 'report.view');

    $this->actingAs($admin)
        ->put("/admin/roles/{$faculty}", ['permissions' => ['qbank.question.view', 'qbank.question.create'], 'reason' => 'New item writers'])
        ->assertRedirect('/admin/roles')
        ->assertSessionHasNoErrors();

    expect(grantsOf($faculty))->toBe(['qbank.question.create', 'qbank.question.view']);

    $entry = DB::table('sec_audit_logs')->where('action', 'admin.role_permissions.changed')->first();
    expect($entry->entity_id)->toBe((string) $faculty)
        ->and($entry->branch_id)->toBeNull()
        ->and($entry->reason)->toBe('New item writers')
        ->and(json_decode($entry->new_values, true))->toMatchArray(['added' => ['qbank.question.create'], 'removed' => ['report.view']])
        ->and(DB::table('sec_role_permissions')->where('permission_code', 'qbank.question.create')->value('granted_by'))->toBe($admin->id);
});

test('role permissions are shared by all campuses, so an administrator of one campus cannot change them', function () {
    $this->cmsBranch('City Campus');
    $admin = $this->adminWith(['admin.roles.manage', 'qbank.question.view'], $this->branch);
    $faculty = $this->cmsRole('Faculty');

    $this->actingAs($admin)->get('/admin/roles')->assertInertia(fn (Assert $page) => $page->where('canEdit', false));
    $this->actingAs($admin)->put("/admin/roles/{$faculty}", ['permissions' => ['qbank.question.view']])->assertForbidden();
    expect(grantsOf($faculty))->toBe([]);
});

test('nobody can grant or revoke a permission they do not hold', function () {
    $admin = $this->adminWith(['admin.roles.manage', 'qbank.question.view'], $this->branch);
    $faculty = $this->cmsRole('Faculty');
    $this->grant($faculty, 'exam.publish');

    $this->actingAs($admin)->put("/admin/roles/{$faculty}", ['permissions' => ['exam.publish', 'audit.export']])->assertForbidden();
    $this->actingAs($admin)->put("/admin/roles/{$faculty}", ['permissions' => []])->assertForbidden();

    expect(grantsOf($faculty))->toBe(['exam.publish'])
        ->and(DB::table('sec_audit_logs')->where('action', 'admin.role_permissions.changed')->exists())->toBeFalse();
});

test('an administrator cannot remove role management from their own role', function () {
    $role = $this->cmsRole('Examinations Admin');
    $this->grant($role, 'admin.roles.manage', 'qbank.question.view');
    $admin = $this->staffUser([$role], $this->branch);
    $this->scope($admin, 'all');

    $this->actingAs($admin)->put("/admin/roles/{$role}", ['permissions' => ['qbank.question.view']])->assertSessionHasErrors('permissions');
    expect(grantsOf($role))->toBe(['admin.roles.manage', 'qbank.question.view']);
});

test('the Super Admin role, unknown roles and unknown permissions are rejected', function () {
    $admin = $this->staffUser([$this->cmsRole('Super Admin', true)], $this->branch);
    $superAdminRole = $this->cmsRole('Another Super Admin', true);
    $faculty = $this->cmsRole('Faculty');

    $this->actingAs($admin)->put("/admin/roles/{$superAdminRole}", ['permissions' => []])->assertSessionHasErrors('role');
    $this->actingAs($admin)->put('/admin/roles/99999999', ['permissions' => []])->assertSessionHasErrors('role');
    $this->actingAs($admin)->put("/admin/roles/{$faculty}", ['permissions' => ["x' OR '1'='1"]])->assertSessionHasErrors('permissions.0');
    $this->actingAs($admin)->put('/admin/roles/abc', ['permissions' => []])->assertNotFound();

    expect(DB::table('sec_role_permissions')->count())->toBe(0);
});
