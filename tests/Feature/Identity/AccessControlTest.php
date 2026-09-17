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

test('the CMS Super Admin role and break-glass accounts have every permission and are privileged', function () {
    $superAdmin = $this->staffUser([$this->cmsRole('Super Admin', true)]);
    $breakGlass = User::factory()->create();
    $breakGlass->forceFill(['is_break_glass' => true])->save();

    foreach ([$superAdmin, $breakGlass] as $user) {
        expect(access()->permissions($user))->toEqualCanonicalizing(Permissions::codes())
            ->and(access()->isPrivileged($user))->toBeTrue()
            ->and(access()->allows($user, 'exam.publish', new ScopeTarget(999, 999, 999)))->toBeTrue();
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

test('scopes limit where a permission applies', function () {
    $role = $this->cmsRole('Faculty');
    $this->grant($role, 'qbank.question.create');

    $everywhere = $this->staffUser([$role]);
    $this->scope($everywhere, 'all');

    $mbbs = $this->staffUser([$role]);
    $this->scope($mbbs, 'programme', 10);

    $firstProf = $this->staffUser([$role]);
    $this->scope($firstProf, 'professional', 100);

    $oneCourse = $this->staffUser([$role]);
    $this->scope($oneCourse, 'course', 1000);

    $noScope = $this->staffUser([$role]);

    $mbbsFirstProfCourse = new ScopeTarget(10, 100, 1000);
    $mbbsOtherCourse = new ScopeTarget(10, 101, 1001);
    $bdsCourse = new ScopeTarget(20, 200, 2000);

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

test('a scope never grants a permission the user does not have', function () {
    $user = $this->staffUser([$this->cmsRole('Faculty')]);
    $this->scope($user, 'all');

    expect(access()->allows($user, 'qbank.question.create', new ScopeTarget(10, 100, 1000)))->toBeFalse();
});

test('course scope targets are resolved from kmu-cms', function () {
    $cms = config('database.cms_source_database');
    DB::statement("SET SESSION sql_mode = ''");
    $programme = DB::table("{$cms}.classes")->insertGetId(['branch_id' => 1, 'education_type_id' => 1, 'class' => 'MBBS Test', 'is_active' => 'no']);
    DB::table("{$cms}.acad_programme_profiles")->insert(['class_id' => $programme, 'code' => 'MBBST', 'calendar_type' => 'annual', 'duration_years' => 5]);
    $professional = DB::table("{$cms}.acad_professionals")->insertGetId(['class_id' => $programme, 'code' => 'PROF-1', 'name' => 'First Professional', 'sequence' => 1]);
    $course = DB::table("{$cms}.acad_courses")->insertGetId(['course_code' => 'MBBST-FND', 'title' => 'Foundation', 'class_id' => $programme, 'professional_id' => $professional, 'course_kind' => 'module']);

    $target = ScopeTarget::course($course);

    expect($target)->not->toBeNull()
        ->and($target->programmeId)->toBe($programme)
        ->and($target->professionalId)->toBe($professional)
        ->and(ScopeTarget::course(99999999))->toBeNull();
});

test('the same scope cannot be added twice, including "all"', function () {
    $user = $this->staffUser();
    $this->scope($user, 'all');

    expect(fn () => $this->scope($user, 'all'))->toThrow(UniqueConstraintViolationException::class);
});

test('only catalogue permissions can be granted', function () {
    expect(fn () => $this->grant($this->cmsRole('X'), 'made.up.permission'))->toThrow(QueryException::class);
});
