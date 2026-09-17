<?php

use App\Domain\Identity\ActiveBranch;
use App\Domain\Identity\Authorization\AccessControl;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCms;

uses(InteractsWithCms::class);

beforeEach(function () {
    $this->shareCmsConnection();
    $this->main = $this->cmsBranch('Main Campus');
    $this->city = $this->cmsBranch('City Campus');
});

test('the campus selected in kmu-cms is the one opened here', function () {
    $user = $this->staffUser([], $this->main);
    $this->cmsGiveBranch((int) $user->cms_staff_id, $this->main);
    $this->cmsGiveBranch((int) $user->cms_staff_id, $this->city);

    $this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => $user->cms_staff_id, 'branch' => $this->city])])->assertRedirect('/dashboard');

    $this->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('branch.id', $this->city)
        ->where('branch.name', 'City Campus')
        ->where('branch.options', [['id' => $this->city, 'name' => 'City Campus'], ['id' => $this->main, 'name' => 'Main Campus']]));

    expect(DB::table('sec_audit_logs')->where('action', 'identity.sso.login')->value('branch_id'))->toBe($this->city);
});

test('a campus the user does not work in, or the CMS "All Branches" view, falls back to their first campus', function () {
    $user = $this->staffUser([], $this->main);

    $this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => $user->cms_staff_id, 'branch' => $this->city])]);
    $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('branch.id', $this->main));

    $this->post('/logout');
    $this->post('/sso/cms', ['ticket' => $this->cmsTicket(['sub' => $user->cms_staff_id])]);
    $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('branch.id', $this->main)->where('branch.options', [['id' => $this->main, 'name' => 'Main Campus']]));
});

test('a user can switch to another of their campuses, but not to someone else\'s', function () {
    $user = $this->staffUser([], $this->main);
    $this->cmsGiveBranch((int) $user->cms_staff_id, $this->main);
    $this->cmsGiveBranch((int) $user->cms_staff_id, $this->city);
    $hill = $this->cmsBranch('Hill Campus');

    $this->actingAs($user)->from('/dashboard')->put('/branch', ['branch_id' => $this->city])->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('branch.id', $this->city));

    $this->actingAs($user)->put('/branch', ['branch_id' => $hill])->assertForbidden();
    $this->actingAs($user)->put('/branch', ['branch_id' => 'all'])->assertSessionHasErrors('branch_id');
    $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('branch.id', $this->city));
});

test('a campus that is closed or taken away is dropped from the session', function () {
    $user = $this->staffUser([], $this->main);
    $this->cmsGiveBranch((int) $user->cms_staff_id, $this->main);
    $this->cmsGiveBranch((int) $user->cms_staff_id, $this->city);
    $activeBranch = app(ActiveBranch::class);

    $this->actingAs($user)->put('/branch', ['branch_id' => $this->city]);
    expect($activeBranch->id($user))->toBe($this->city);

    DB::table(config('database.cms_source_database').'.branches')->where('id', $this->city)->update(['status' => 'inactive']);
    app(AccessControl::class)->forget($user);

    expect($activeBranch->id($user))->toBe($this->main)
        ->and(session('active_branch_id'))->toBe($this->main);
});

test('a user with no campus has none to work in', function () {
    $user = $this->staffUser();

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('branch.id', null)
        ->where('branch.name', null)
        ->where('branch.options', []));
});
