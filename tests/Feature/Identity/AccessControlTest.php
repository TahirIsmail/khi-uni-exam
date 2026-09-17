<?php

use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\Permissions;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
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

test('permissions come from the CMS roles a staff member holds', function () {
    $faculty = $this->cmsRole('Faculty');
    $reviewer = $this->cmsRole('Reviewer');
    $this->grant($faculty, 'qbank.question.view', 'qbank.question.create');
    $this->grant($reviewer, 'qbank.review.perform');

    $author = $this->staffUser([$faculty]);
    $both = $this->staffUser([$faculty, $reviewer]);

    expect(access()->has($author, 'qbank.question.create'))->toBeTrue()
        ->and(access()->has($author, 'qbank.review.perform'))->toBeFalse()
        ->and(access()->permissions($both))->toEqualCanonicalizing(['qbank.question.view', 'qbank.question.create', 'qbank.review.perform']);
});

test('a staff member without granted roles has no permissions', function () {
    $user = $this->staffUser([$this->cmsRole('Receptionist')]);

    expect(access()->permissions($user))->toBe([])
        ->and(access()->isPrivileged($user))->toBeFalse();
});

test('a local account without a CMS link and without break-glass has no permissions', function () {
    $user = User::factory()->create();

    expect(access()->permissions($user))->toBe([]);
});

test('the CMS Super Admin role and break-glass accounts have every permission in every active branch', function () {
    $main = $this->cmsBranch('Main Campus');
    $city = $this->cmsBranch('City Campus');
    $closed = $this->cmsBranch('Closed Campus', 'inactive');

    $superAdmin = $this->staffUser([$this->cmsRole('Super Admin', true)]);
    $breakGlass = User::factory()->create();
    $breakGlass->forceFill(['is_break_glass' => true])->save();

    foreach ([$superAdmin, $breakGlass] as $user) {
        expect(access()->permissions($user))->toEqualCanonicalizing(Permissions::codes())
            ->and(access()->isPrivileged($user))->toBeTrue()
            ->and(access()->branchIds($user))->toBe([$main, $city])
            ->and(access()->allows($user, 'exam.publish', new ScopeTarget($city, 999, 999, 999)))->toBeTrue()
            ->and(access()->allows($user, 'exam.publish', ScopeTarget::branch($closed)))->toBeFalse();
    }
});

test('holding any privileged permission makes a user privileged (MFA required)', function () {
    $approver = $this->cmsRole('Approver');
    $this->grant($approver, 'qbank.question.view', 'qbank.question.approve');

    expect(access()->isPrivileged($this->staffUser([$approver])))->toBeTrue();
});

test('an inactive user has no permissions even if granted', function () {
    $role = $this->cmsRole('Faculty');
    $this->grant($role, 'qbank.question.view');
    $user = $this->staffUser([$role]);
    $user->forceFill(['is_active' => false])->save();

    expect(access()->has($user, 'qbank.question.view'))->toBeFalse();
});

test('the Gate answers catalogue permissions through access control', function () {
    $role = $this->cmsRole('Faculty');
    $this->grant($role, 'qbank.question.view');
    $user = $this->staffUser([$role]);

    expect(Gate::forUser($user)->allows('qbank.question.view'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('exam.publish'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('not.a.catalogue.permission'))->toBeFalse();
});

test('scopes limit where a permission applies inside the user\'s branch', function () {
    $branch = $this->cmsBranch();
    $role = $this->cmsRole('Faculty');
    $this->grant($role, 'qbank.question.create');

    $everywhere = $this->staffUser([$role], $branch);
    $this->scope($everywhere, 'all');

    $mbbs = $this->staffUser([$role], $branch);
    $this->scope($mbbs, 'programme', 10);

    $firstProf = $this->staffUser([$role], $branch);
    $this->scope($firstProf, 'professional', 100);

    $oneCourse = $this->staffUser([$role], $branch);
    $this->scope($oneCourse, 'course', 1000);

    $noScope = $this->staffUser([$role], $branch);

    $mbbsFirstProfCourse = new ScopeTarget($branch, 10, 100, 1000);
    $mbbsOtherCourse = new ScopeTarget($branch, 10, 101, 1001);
    $bdsCourse = new ScopeTarget($branch, 20, 200, 2000);

    expect(access()->allows($everywhere, 'qbank.question.create', $bdsCourse))->toBeTrue()
        ->and(access()->allows($mbbs, 'qbank.question.create', $mbbsOtherCourse))->toBeTrue()
        ->and(access()->allows($mbbs, 'qbank.question.create', $bdsCourse))->toBeFalse()
        ->and(access()->allows($firstProf, 'qbank.question.create', $mbbsFirstProfCourse))->toBeTrue()
        ->and(access()->allows($firstProf, 'qbank.question.create', $mbbsOtherCourse))->toBeFalse()
        ->and(access()->allows($oneCourse, 'qbank.question.create', $mbbsFirstProfCourse))->toBeTrue()
        ->and(access()->allows($oneCourse, 'qbank.question.create', $mbbsOtherCourse))->toBeFalse()
        ->and(access()->allows($noScope, 'qbank.question.create', $mbbsFirstProfCourse))->toBeFalse()
        ->and(access()->allows($noScope, 'qbank.question.create'))->toBeTrue();
});

test('an "all" scope never reaches another branch', function () {
    $main = $this->cmsBranch('Main Campus');
    $city = $this->cmsBranch('City Campus');
    $role = $this->cmsRole('Faculty');
    $this->grant($role, 'qbank.question.create');
    $user = $this->staffUser([$role], $main);
    $this->scope($user, 'all');
    $this->scope($user, 'programme', 20);

    expect(access()->branchIds($user))->toBe([$main])
        ->and(access()->allows($user, 'qbank.question.create', ScopeTarget::branch($main)))->toBeTrue()
        ->and(access()->allows($user, 'qbank.question.create', ScopeTarget::branch($city)))->toBeFalse()
        ->and(access()->allows($user, 'qbank.question.create', new ScopeTarget($city, 20)))->toBeFalse();
});

test('extra branches from kmu-cms replace the staff member\'s own branch, and inactive ones are ignored', function () {
    $main = $this->cmsBranch('Main Campus');
    $city = $this->cmsBranch('City Campus');
    $hill = $this->cmsBranch('Hill Campus');
    $closed = $this->cmsBranch('Closed Campus', 'inactive');
    $user = $this->staffUser([], $main);
    $this->cmsGiveBranch((int) $user->cms_staff_id, $hill);
    $this->cmsGiveBranch((int) $user->cms_staff_id, $city);
    $this->cmsGiveBranch((int) $user->cms_staff_id, $closed);

    expect(access()->branchIds($user))->toBe([$city, $hill])
        ->and(access()->canAccessBranch($user, $main))->toBeFalse();
});

test('no branch access without an active own branch, a CMS link, or an active account', function () {
    $closed = $this->cmsBranch('Closed Campus', 'inactive');
    $main = $this->cmsBranch('Main Campus');
    $inactiveUser = $this->staffUser([], $main);
    $inactiveUser->forceFill(['is_active' => false])->save();

    expect(access()->branchIds($this->staffUser([], $closed)))->toBe([])
        ->and(access()->branchIds($this->staffUser()))->toBe([])
        ->and(access()->branchIds(User::factory()->create()))->toBe([])
        ->and(access()->branchIds($inactiveUser))->toBe([]);
});

test('a scope never grants a permission the user does not have', function () {
    $branch = $this->cmsBranch();
    $user = $this->staffUser([$this->cmsRole('Faculty')], $branch);
    $this->scope($user, 'all');

    expect(access()->allows($user, 'qbank.question.create', new ScopeTarget($branch, 10, 100, 1000)))->toBeFalse();
});

test('scope targets take their branch, programme and professional from kmu-cms', function () {
    $cms = config('database.cms_source_database');
    $branch = $this->cmsBranch();
    DB::statement("SET SESSION sql_mode = ''");
    $programme = (int) DB::table("{$cms}.classes")->insertGetId(['branch_id' => $branch, 'education_type_id' => 1, 'class' => 'MBBS Test', 'is_active' => 'no']);
    DB::table("{$cms}.acad_programme_profiles")->insert(['class_id' => $programme, 'code' => 'MBBST', 'calendar_type' => 'annual', 'duration_years' => 5]);
    $professional = (int) DB::table("{$cms}.acad_professionals")->insertGetId(['class_id' => $programme, 'code' => 'PROF-1', 'name' => 'First Professional', 'sequence' => 1]);
    $course = (int) DB::table("{$cms}.acad_courses")->insertGetId(['course_code' => 'MBBST-FND', 'title' => 'Foundation', 'class_id' => $programme, 'professional_id' => $professional, 'course_kind' => 'module']);

    expect(ScopeTarget::course($course))->toEqual(new ScopeTarget($branch, $programme, $professional, $course))
        ->and(ScopeTarget::professional($professional))->toEqual(new ScopeTarget($branch, $programme, $professional))
        ->and(ScopeTarget::programme($programme))->toEqual(new ScopeTarget($branch, $programme))
        ->and(ScopeTarget::course(99999999))->toBeNull()
        ->and(ScopeTarget::professional(99999999))->toBeNull()
        ->and(ScopeTarget::programme(99999999))->toBeNull();
});

test('the same scope cannot be added twice, including "all"', function () {
    $user = $this->staffUser();
    $this->scope($user, 'all');

    expect(fn () => $this->scope($user, 'all'))->toThrow(UniqueConstraintViolationException::class);
});

test('only catalogue permissions can be granted', function () {
    expect(fn () => $this->grant($this->cmsRole('X'), 'made.up.permission'))->toThrow(QueryException::class);
});
