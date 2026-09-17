<?php

use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\Permissions;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\InteractsWithCms;

uses(InteractsWithCms::class);

beforeEach(function () {
    $this->shareCmsConnection();
});

function access(): AccessControl
{
    return app(AccessControl::class);
}

test('every permission code maps to exactly one kmu-cms checkbox', function () {
    $codes = Permissions::codes();

    expect($codes)->toHaveCount(count(array_unique($codes)));
    foreach (Permissions::CATALOGUE as $permissions) {
        foreach ($permissions as [$description, $category, $checkbox]) {
            expect($description)->not->toBe('')
                ->and($category)->toMatch('/^[a-z_]+$/')
                ->and($checkbox)->toBeIn(['view', 'add', 'edit', 'delete']);
        }
    }
});

test('permissions come from the checkboxes ticked for the staff member\'s roles in kmu-cms', function () {
    $faculty = $this->cmsRole('Faculty');
    $reviewer = $this->cmsRole('Reviewer');
    $this->cmsGrant($faculty, 'qbank_questions', 'view', 'add');
    $this->cmsGrant($reviewer, 'qbank_review', 'view');

    $author = $this->staffUser([$faculty]);
    $both = $this->staffUser([$faculty, $reviewer]);

    expect(access()->permissions($author))->toEqualCanonicalizing(['qbank.question.view', 'qbank.question.create', 'qbank.question.edit_own', 'qbank.question.submit'])
        ->and(access()->has($author, 'qbank.review.perform'))->toBeFalse()
        ->and(access()->has($author, 'qbank.question.edit_any'))->toBeFalse()
        ->and(access()->has($both, 'qbank.review.perform'))->toBeTrue();
});

test('checkboxes of other permission groups grant nothing here', function () {
    $role = $this->cmsRole('Accountant');
    $this->cmsPermissionCatalogue();
    $cms = config('database.cms_source_database');
    $otherGroup = DB::table("{$cms}.permission_group")->insertGetId(['name' => 'Fees', 'short_code' => 'fees_collection', 'is_active' => 1, 'system' => 0]);
    $lookalike = DB::table("{$cms}.permission_category")->insertGetId(['perm_group_id' => $otherGroup, 'name' => 'Questions', 'short_code' => 'qbank_questions_fake', 'enable_view' => 1]);
    DB::table("{$cms}.roles_permissions")->insert(['role_id' => $role, 'perm_cat_id' => $lookalike, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1]);

    expect(access()->permissions($this->staffUser([$role])))->toBe([]);
});

test('a staff member whose roles have no exam checkboxes, or who has no CMS link, has no permissions', function () {
    expect(access()->permissions($this->staffUser([$this->cmsRole('Receptionist')])))->toBe([])
        ->and(access()->permissions(User::factory()->create()))->toBe([]);
});

test('a CMS Super Admin has every permission in every active campus', function () {
    $main = $this->cmsBranch('Main Campus');
    $city = $this->cmsBranch('City Campus');
    $closed = $this->cmsBranch('Closed Campus', 'inactive');
    $superAdmin = $this->staffUser([$this->cmsRole('Super Admin', true)]);
    $this->cmsExamScope($superAdmin, 'course', 5);

    expect(access()->permissions($superAdmin))->toEqualCanonicalizing(Permissions::codes())
        ->and(access()->branchIds($superAdmin))->toBe([$main, $city])
        ->and(access()->allows($superAdmin, 'exam.publish', new ScopeTarget($city, 999, 999, 999)))->toBeTrue()
        ->and(access()->allows($superAdmin, 'exam.publish', ScopeTarget::branch($closed)))->toBeFalse();
});

test('an inactive user has no permissions or campuses even if granted', function () {
    $branch = $this->cmsBranch();
    $role = $this->cmsRole('Faculty');
    $this->cmsGrant($role, 'qbank_questions', 'view');
    $user = $this->staffUser([$role], $branch);
    $user->forceFill(['is_active' => false])->save();

    expect(access()->has($user, 'qbank.question.view'))->toBeFalse()
        ->and(access()->branchIds($user))->toBe([]);
});

test('the Gate answers catalogue permissions through access control', function () {
    $role = $this->cmsRole('Faculty');
    $this->cmsGrant($role, 'qbank_questions', 'view');
    $user = $this->staffUser([$role]);

    expect(Gate::forUser($user)->allows('qbank.question.view'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('exam.publish'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('not.a.catalogue.permission'))->toBeFalse();
});

test('without exam access limits a user works everywhere in their campuses, never in another campus', function () {
    $main = $this->cmsBranch('Main Campus');
    $city = $this->cmsBranch('City Campus');
    $role = $this->cmsRole('Faculty');
    $this->cmsGrant($role, 'qbank_questions', 'view', 'add');
    $user = $this->staffUser([$role], $main);

    expect(access()->scopes($user))->toBe([])
        ->and(access()->allows($user, 'qbank.question.create', new ScopeTarget($main, 10, 100, 1000)))->toBeTrue()
        ->and(access()->allows($user, 'qbank.question.create', new ScopeTarget($main, 20, 200, 2000)))->toBeTrue()
        ->and(access()->allows($user, 'qbank.question.create', new ScopeTarget($city, 10, 100, 1000)))->toBeFalse()
        ->and(access()->allows($user, 'exam.publish', new ScopeTarget($main, 10)))->toBeFalse();
});

test('exam access limits from kmu-cms restrict where a permission applies', function () {
    $branch = $this->cmsBranch();
    $role = $this->cmsRole('Faculty');
    $this->cmsGrant($role, 'qbank_questions', 'view', 'add');

    $mbbs = $this->staffUser([$role], $branch);
    $this->cmsExamScope($mbbs, 'programme', 10);

    $firstProf = $this->staffUser([$role], $branch);
    $this->cmsExamScope($firstProf, 'professional', 100);

    $twoCourses = $this->staffUser([$role], $branch);
    $this->cmsExamScope($twoCourses, 'course', 1000);
    $this->cmsExamScope($twoCourses, 'course', 1002);

    $mbbsFirstProfCourse = new ScopeTarget($branch, 10, 100, 1000);
    $mbbsOtherCourse = new ScopeTarget($branch, 10, 101, 1001);
    $bdsCourse = new ScopeTarget($branch, 20, 200, 2000);

    expect(access()->allows($mbbs, 'qbank.question.create', $mbbsOtherCourse))->toBeTrue()
        ->and(access()->allows($mbbs, 'qbank.question.create', $bdsCourse))->toBeFalse()
        ->and(access()->allows($firstProf, 'qbank.question.create', $mbbsFirstProfCourse))->toBeTrue()
        ->and(access()->allows($firstProf, 'qbank.question.create', $mbbsOtherCourse))->toBeFalse()
        ->and(access()->allows($twoCourses, 'qbank.question.create', $mbbsFirstProfCourse))->toBeTrue()
        ->and(access()->allows($twoCourses, 'qbank.question.create', new ScopeTarget($branch, 10, 101, 1002)))->toBeTrue()
        ->and(access()->allows($twoCourses, 'qbank.question.create', $mbbsOtherCourse))->toBeFalse()
        ->and(access()->allows($twoCourses, 'qbank.question.create'))->toBeTrue();
});

test('a limit never grants a permission the user does not have, nor another campus', function () {
    $main = $this->cmsBranch('Main Campus');
    $city = $this->cmsBranch('City Campus');
    $user = $this->staffUser([$this->cmsRole('Faculty')], $main);
    $this->cmsExamScope($user, 'programme', 10);

    expect(access()->allows($user, 'qbank.question.create', new ScopeTarget($main, 10)))->toBeFalse();

    $role = $this->cmsRole('Writer');
    $this->cmsGrant($role, 'qbank_questions', 'add');
    $writer = $this->staffUser([$role], $main);
    $this->cmsExamScope($writer, 'programme', 10);

    expect(access()->allows($writer, 'qbank.question.create', new ScopeTarget($city, 10)))->toBeFalse();
});

test('extra campuses from kmu-cms replace the staff member\'s own campus, and inactive ones are ignored', function () {
    $main = $this->cmsBranch('Main Campus');
    $city = $this->cmsBranch('City Campus');
    $hill = $this->cmsBranch('Hill Campus');
    $closed = $this->cmsBranch('Closed Campus', 'inactive');
    $user = $this->staffUser([], $main);
    $this->cmsGiveBranch((int) $user->cms_staff_id, $hill);
    $this->cmsGiveBranch((int) $user->cms_staff_id, $city);
    $this->cmsGiveBranch((int) $user->cms_staff_id, $closed);

    expect(access()->branchIds($user))->toBe([$city, $hill])
        ->and(access()->canAccessBranch($user, $main))->toBeFalse()
        ->and(access()->branchIds($this->staffUser([], $closed)))->toBe([])
        ->and(access()->branchIds($this->staffUser()))->toBe([]);
});

test('scope targets take their campus, programme and professional from kmu-cms', function () {
    $branch = $this->cmsBranch();
    $programme = $this->cmsProgramme($branch, 'MBBS');
    $professional = $this->cmsProfessional($programme);
    $course = $this->cmsCourse($programme, $professional);

    expect(ScopeTarget::course($course))->toEqual(new ScopeTarget($branch, $programme, $professional, $course))
        ->and(ScopeTarget::professional($professional))->toEqual(new ScopeTarget($branch, $programme, $professional))
        ->and(ScopeTarget::programme($programme))->toEqual(new ScopeTarget($branch, $programme))
        ->and(ScopeTarget::course(99999999))->toBeNull()
        ->and(ScopeTarget::professional(99999999))->toBeNull()
        ->and(ScopeTarget::programme(99999999))->toBeNull();
});

test('cms:check-permissions confirms every checkbox exists and reports missing ones', function () {
    $this->cmsPermissionCatalogue();
    $this->artisan('cms:check-permissions')->assertSuccessful();

    DB::table(config('database.cms_source_database').'.permission_category')->where('short_code', 'exam_papers_publish')->delete();
    DB::table(config('database.cms_source_database').'.permission_category')->where('short_code', 'qbank_questions')->update(['enable_delete' => 0]);

    $this->artisan('cms:check-permissions')
        ->expectsOutputToContain('exam_papers_publish is missing')
        ->expectsOutputToContain("qbank_questions has no 'delete' checkbox")
        ->assertFailed();
});
