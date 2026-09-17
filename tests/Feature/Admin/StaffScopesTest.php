<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class);

beforeEach(function () {
    $this->shareCmsConnection();
    $this->main = $this->cmsBranch('Main Campus');
    $this->city = $this->cmsBranch('City Campus');
});

function scopesOf(User $user): array
{
    return DB::table('sec_user_scopes')->where('user_id', $user->id)->orderBy('id')->get(['scope_type', 'scope_id'])
        ->map(fn ($s) => [$s->scope_type, $s->scope_id === null ? null : (int) $s->scope_id])->all();
}

test('staff scopes need the manage permission', function () {
    $user = $this->adminWith(['qbank.question.view'], $this->main);
    $other = $this->staffUser([], $this->main);

    $this->actingAs($user)->get('/admin/staff')->assertForbidden();
    $this->actingAs($user)->get("/admin/staff/{$other->cms_staff_id}")->assertForbidden();
    $this->actingAs($user)->post("/admin/staff/{$other->cms_staff_id}/scopes", ['scope_type' => 'all'])->assertForbidden();
    expect(scopesOf($other))->toBe([]);
});

test('the staff list shows only active staff of the administrator\'s campuses and escapes search wildcards', function () {
    $admin = $this->adminWith(['admin.users.manage'], $this->main);
    $mainStaff = $this->cmsStaff(['name' => 'Zara', 'branch_id' => $this->main]);
    $cityStaff = $this->cmsStaff(['name' => 'Bilal', 'branch_id' => $this->city]);
    $visiting = $this->cmsStaff(['name' => 'Hina', 'branch_id' => $this->city]);
    $this->cmsGiveBranch($visiting, $this->main);
    $this->cmsStaff(['name' => 'Left', 'branch_id' => $this->main, 'is_active' => 0]);

    $this->actingAs($admin)->get('/admin/staff')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/Staff')
        ->where('staff.data', fn ($rows) => collect($rows)->pluck('staffId')->sort()->values()->all() === collect([$admin->cms_staff_id, $mainStaff, $visiting])->sort()->values()->all()));

    $this->actingAs($admin)->get('/admin/staff?search=Zar')->assertInertia(fn (Assert $page) => $page
        ->where('staff.data', fn ($rows) => collect($rows)->pluck('staffId')->all() === [$mainStaff]));
    $this->actingAs($admin)->get('/admin/staff?search=%25')->assertInertia(fn (Assert $page) => $page->where('staff.total', 0));

    expect($cityStaff)->toBeInt();
});

test('an administrator cannot open or change staff of another campus, or themselves', function () {
    $admin = $this->adminWith(['admin.users.manage'], $this->main);
    $cityUser = $this->staffUser([], $this->city);

    $this->actingAs($admin)->get("/admin/staff/{$cityUser->cms_staff_id}")->assertForbidden();
    $this->actingAs($admin)->post("/admin/staff/{$cityUser->cms_staff_id}/scopes", ['scope_type' => 'all'])->assertForbidden();
    $this->actingAs($admin)->get("/admin/staff/{$admin->cms_staff_id}")->assertForbidden();
    $this->actingAs($admin)->post("/admin/staff/{$admin->cms_staff_id}/scopes", ['scope_type' => 'programme', 'scope_id' => $this->cmsProgramme($this->main)])->assertForbidden();
    $this->actingAs($admin)->get('/admin/staff/99999999')->assertForbidden();

    expect(scopesOf($cityUser))->toBe([])->and(scopesOf($admin))->toBe([['all', null]]);
});

test('scopes can be set up before a staff member first signs in; opening the page creates nothing', function () {
    $admin = $this->adminWith(['admin.users.manage'], $this->main);
    $staffId = $this->cmsStaff(['name' => 'Sana', 'surname' => 'Ali', 'branch_id' => $this->main]);
    $programme = $this->cmsProgramme($this->main, 'MBBS');

    $this->actingAs($admin)->get("/admin/staff/{$staffId}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/StaffScopes')
        ->where('staff.name', 'Sana Ali')
        ->where('staff.branches', ['Main Campus'])
        ->where('canAddAll', true)
        ->where('options', fn ($options) => collect($options)->contains(fn ($o) => $o['type'] === 'programme' && $o['id'] === $programme)));
    expect(User::query()->where('cms_staff_id', $staffId)->exists())->toBeFalse();

    $this->actingAs($admin)->post("/admin/staff/{$staffId}/scopes", ['scope_type' => 'programme', 'scope_id' => $programme])
        ->assertRedirect("/admin/staff/{$staffId}")->assertSessionHasNoErrors();

    $user = User::query()->where('cms_staff_id', $staffId)->firstOrFail();
    expect(scopesOf($user))->toBe([['programme', $programme]])
        ->and($user->password)->toBeNull()
        ->and($user->last_login_at)->toBeNull();

    $entry = DB::table('sec_audit_logs')->where('action', 'admin.user_scope.added')->first();
    expect((int) $entry->branch_id)->toBe($this->main)
        ->and((int) $entry->actor_id)->toBe($admin->id)
        ->and(json_decode($entry->new_values, true))->toEqual(['scope_type' => 'programme', 'scope_id' => $programme]);
});

test('a scope must be in a campus both people work in', function () {
    $admin = $this->adminWith(['admin.users.manage'], $this->main);
    $this->cmsGiveBranch((int) $admin->cms_staff_id, $this->main);
    $this->cmsGiveBranch((int) $admin->cms_staff_id, $this->city);
    $mainOnly = $this->staffUser([], $this->main);
    $cityProgramme = $this->cmsProgramme($this->city, 'DPT');
    $foreignBranch = $this->cmsBranch('Hill Campus');
    $hillProgramme = $this->cmsProgramme($foreignBranch, 'BDS');

    $this->actingAs($admin)->post("/admin/staff/{$mainOnly->cms_staff_id}/scopes", ['scope_type' => 'programme', 'scope_id' => $cityProgramme])->assertSessionHasErrors('scope_id');
    $this->actingAs($admin)->post("/admin/staff/{$mainOnly->cms_staff_id}/scopes", ['scope_type' => 'programme', 'scope_id' => $hillProgramme])->assertForbidden();
    $this->actingAs($admin)->post("/admin/staff/{$mainOnly->cms_staff_id}/scopes", ['scope_type' => 'course', 'scope_id' => 99999999])->assertSessionHasErrors('scope_id');

    expect(scopesOf($mainOnly))->toBe([]);
});

test('an administrator limited to one programme can only hand out places inside it', function () {
    $mbbs = $this->cmsProgramme($this->main, 'MBBS');
    $bds = $this->cmsProgramme($this->main, 'BDS');
    $firstProf = $this->cmsProfessional($mbbs);
    $anatomy = $this->cmsCourse($mbbs, $firstProf, 'ANA');
    $admin = $this->adminWith(['admin.users.manage'], $this->main, allScope: false);
    $this->scope($admin, 'programme', $mbbs);
    $faculty = $this->staffUser([], $this->main);

    $this->actingAs($admin)->get("/admin/staff/{$faculty->cms_staff_id}")->assertInertia(fn (Assert $page) => $page
        ->where('canAddAll', false)
        ->where('options', fn ($options) => collect($options)->pluck('id', 'type')->all() !== [] && ! collect($options)->contains(fn ($o) => $o['type'] === 'programme' && $o['id'] === $bds)));

    $this->actingAs($admin)->post("/admin/staff/{$faculty->cms_staff_id}/scopes", ['scope_type' => 'course', 'scope_id' => $anatomy])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post("/admin/staff/{$faculty->cms_staff_id}/scopes", ['scope_type' => 'programme', 'scope_id' => $bds])->assertForbidden();
    $this->actingAs($admin)->post("/admin/staff/{$faculty->cms_staff_id}/scopes", ['scope_type' => 'all'])->assertForbidden();

    expect(scopesOf($faculty))->toBe([['course', $anatomy]]);
});

test('an "all" scope needs the administrator to manage every campus of that person', function () {
    $admin = $this->adminWith(['admin.users.manage'], $this->main);
    $travelling = $this->staffUser([], $this->main);
    $this->cmsGiveBranch((int) $travelling->cms_staff_id, $this->main);
    $this->cmsGiveBranch((int) $travelling->cms_staff_id, $this->city);
    $local = $this->staffUser([], $this->main);

    $this->actingAs($admin)->post("/admin/staff/{$travelling->cms_staff_id}/scopes", ['scope_type' => 'all'])->assertForbidden();
    $this->actingAs($admin)->post("/admin/staff/{$local->cms_staff_id}/scopes", ['scope_type' => 'all'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post("/admin/staff/{$local->cms_staff_id}/scopes", ['scope_type' => 'all', 'scope_id' => 5])->assertSessionHasErrors('scope_id');

    expect(scopesOf($travelling))->toBe([])
        ->and(scopesOf($local))->toBe([['all', null]])
        ->and(DB::table('sec_audit_logs')->where('action', 'admin.user_scope.added')->value('branch_id'))->toBeNull();
});

test('removing a scope is audited and only works through the staff member it belongs to', function () {
    $admin = $this->adminWith(['admin.users.manage'], $this->main);
    $programme = $this->cmsProgramme($this->main);
    $faculty = $this->staffUser([], $this->main);
    $other = $this->staffUser([], $this->main);
    $this->scope($faculty, 'programme', $programme);
    $scopeId = (int) DB::table('sec_user_scopes')->where('user_id', $faculty->id)->value('id');

    $this->actingAs($admin)->delete("/admin/staff/{$other->cms_staff_id}/scopes/{$scopeId}")->assertForbidden();
    expect(scopesOf($faculty))->toBe([['programme', $programme]]);

    $this->actingAs($admin)->delete("/admin/staff/{$faculty->cms_staff_id}/scopes/{$scopeId}")->assertRedirect("/admin/staff/{$faculty->cms_staff_id}");
    expect(scopesOf($faculty))->toBe([])
        ->and(json_decode((string) DB::table('sec_audit_logs')->where('action', 'admin.user_scope.removed')->value('old_values'), true))->toEqual(['scope_type' => 'programme', 'scope_id' => $programme]);
});

test('invalid input is rejected', function () {
    $admin = $this->adminWith(['admin.users.manage'], $this->main);
    $faculty = $this->staffUser([], $this->main);

    $this->actingAs($admin)->post("/admin/staff/{$faculty->cms_staff_id}/scopes", ['scope_type' => 'branch', 'scope_id' => 1])->assertSessionHasErrors('scope_type');
    $this->actingAs($admin)->post("/admin/staff/{$faculty->cms_staff_id}/scopes", ['scope_type' => 'programme', 'scope_id' => '1 OR 1=1'])->assertSessionHasErrors('scope_id');
    $this->actingAs($admin)->post("/admin/staff/{$faculty->cms_staff_id}/scopes", ['scope_type' => 'programme'])->assertSessionHasErrors('scope_id');
    $this->actingAs($admin)->get('/admin/staff/1abc')->assertNotFound();

    $programme = $this->cmsProgramme($this->main);
    $this->actingAs($admin)->post("/admin/staff/{$faculty->cms_staff_id}/scopes", ['scope_type' => 'programme', 'scope_id' => $programme]);
    $this->actingAs($admin)->post("/admin/staff/{$faculty->cms_staff_id}/scopes", ['scope_type' => 'programme', 'scope_id' => $programme])->assertSessionHasErrors('scope_id');

    expect(scopesOf($faculty))->toBe([['programme', $programme]]);
});
