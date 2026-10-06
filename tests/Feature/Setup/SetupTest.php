<?php

use App\Domain\Identity\Authorization\AccessControl;
use App\Models\User;
use App\Support\Cms\CmsSettings;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCms;

uses(InteractsWithCms::class);

beforeEach(function () {
    $this->shareCmsConnection();
    $this->cmsPermissionCatalogue();
    $this->branch = $this->cmsBranch('University');
    $this->superRole = $this->cmsRole('Super Admin', true);
    $this->admin = $this->staffUser([$this->superRole], $this->branch);
    $this->cms = config('database.cms_source_database');
});

test('only a Super Admin can open Setup', function () {
    $this->actingAs($this->admin)->get('/setup')->assertOk()->assertInertia(fn ($page) => $page->component('setup/Index'));
    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn ($page) => $page->where('auth.can.manageSetup', true));

    $setter = $this->staffUser([$this->cmsRole('Paper Setter')], $this->branch);
    foreach (['/setup', '/setup/staff', '/setup/roles', '/setup/programmes', '/setup/lists'] as $url) {
        $this->actingAs($setter)->get($url)->assertForbidden();
    }
    $this->actingAs($setter)->post('/setup/roles', ['name' => 'Sneaky'])->assertForbidden();
});

test('a role\'s ticked boxes become the permissions of whoever holds it', function () {
    $this->actingAs($this->admin)->post('/setup/roles', ['name' => 'Paper Setter'])->assertSessionHasNoErrors();
    $roleId = (int) DB::table("{$this->cms}.roles")->where('name', 'Paper Setter')->value('id');
    $questions = (int) DB::table("{$this->cms}.permission_category")->where('short_code', 'qbank_questions')->value('id');
    $blueprints = (int) DB::table("{$this->cms}.permission_category")->where('short_code', 'exam_blueprints_approve')->value('id');

    // "delete" is not offered on Approve Blueprints, so it is ignored.
    $this->actingAs($this->admin)->put("/setup/roles/{$roleId}", [
        'name' => 'Paper Setter',
        'grants' => [$questions => ['view', 'add'], $blueprints => ['view', 'delete']],
    ])->assertSessionHasNoErrors();

    $setter = $this->staffUser([$roleId], $this->branch);
    $access = app(AccessControl::class);
    expect($access->has($setter, 'qbank.question.view'))->toBeTrue()
        ->and($access->has($setter, 'qbank.question.create'))->toBeTrue()
        ->and($access->has($setter, 'qbank.question.edit_any'))->toBeFalse()
        ->and($access->has($setter, 'exam.blueprint.approve'))->toBeTrue()
        ->and(DB::table("{$this->cms}.roles_permissions")->where('perm_cat_id', $blueprints)->value('can_delete'))->toBe(0);

    // A role somebody holds cannot be deleted; nor can the Super Admin role.
    $this->actingAs($this->admin)->delete("/setup/roles/{$roleId}")->assertSessionHasErrors('role');
    $this->actingAs($this->admin)->delete("/setup/roles/{$this->superRole}")->assertSessionHasErrors('role');
});

test('a staff member added under Setup can sign in with the roles and course limits given', function () {
    $role = $this->cmsRole('Examiner');
    $programme = $this->cmsProgramme($this->branch);
    $course = $this->cmsCourse($programme, $this->cmsProfessional($programme));

    $this->actingAs($this->admin)->post('/setup/staff', [
        'name' => 'Sana', 'surname' => 'Ali', 'email' => 'Sana.Ali@uni.test', 'phone' => '0300',
        'branch_id' => $this->branch, 'is_active' => true, 'role_ids' => [$role], 'course_ids' => [$course],
        'password' => 'short',
    ])->assertSessionHasErrors('password');

    $this->actingAs($this->admin)->post('/setup/staff', [
        'name' => 'Sana', 'surname' => 'Ali', 'email' => 'Sana.Ali@uni.test', 'phone' => '0300',
        'branch_id' => $this->branch, 'is_active' => true, 'role_ids' => [$role], 'course_ids' => [$course],
        'password' => 'sana-password-1',
    ])->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'sana.ali@uni.test')->firstOrFail();
    $staffId = (int) $user->cms_staff_id;
    expect(DB::table("{$this->cms}.staff")->where('id', $staffId)->value('email'))->toBe('sana.ali@uni.test')
        ->and(app(AccessControl::class)->roles($user)[0]->name)->toBe('Examiner')
        ->and(app(AccessControl::class)->scopes($user)[0]->id)->toBe($course);

    auth()->logout();
    $this->post('/login', ['email' => 'sana.ali@uni.test', 'password' => 'sana-password-1'])->assertRedirect('/dashboard');
    auth()->logout();

    // The same email twice is refused; switching the account off stops sign-in; a new password works.
    $this->actingAs($this->admin)->post('/setup/staff', [
        'name' => 'Other', 'email' => 'sana.ali@uni.test', 'branch_id' => $this->branch, 'role_ids' => [$role], 'password' => 'another-pass-1',
    ])->assertSessionHasErrors('email');
    $this->actingAs($this->admin)->put("/setup/staff/{$staffId}", [
        'name' => 'Sana', 'email' => 'sana.ali@uni.test', 'branch_id' => $this->branch, 'is_active' => false, 'role_ids' => [$role],
    ])->assertSessionHasNoErrors();
    auth()->logout();
    $this->post('/login', ['email' => 'sana.ali@uni.test', 'password' => 'sana-password-1'])->assertSessionHasErrors('email');

    $this->actingAs($this->admin)->put("/setup/staff/{$staffId}", [
        'name' => 'Sana', 'email' => 'sana.ali@uni.test', 'branch_id' => $this->branch, 'is_active' => true, 'role_ids' => [$role], 'password' => 'new-password-22',
    ])->assertSessionHasNoErrors();
    auth()->logout();
    $this->post('/login', ['email' => 'sana.ali@uni.test', 'password' => 'new-password-22'])->assertRedirect('/dashboard');
});

test('a Super Admin cannot lock themselves out', function () {
    $other = $this->cmsRole('Examiner');

    $this->actingAs($this->admin)->put("/setup/staff/{$this->admin->cms_staff_id}", [
        'name' => 'Me', 'email' => $this->admin->email, 'branch_id' => $this->branch, 'is_active' => true, 'role_ids' => [$other],
    ])->assertSessionHasErrors('role_ids');
    $this->actingAs($this->admin)->put("/setup/staff/{$this->admin->cms_staff_id}", [
        'name' => 'Me', 'email' => $this->admin->email, 'branch_id' => $this->branch, 'is_active' => false, 'role_ids' => [$this->superRole],
    ])->assertSessionHasErrors('role_ids');
});

test('a programme, its course and topics set up under Setup are what questions and exams hang on', function () {
    $this->actingAs($this->admin)->post('/setup/programmes', [
        'name' => 'Admissions Entry Test', 'code' => 'ENTRY', 'calendar' => 'annual', 'structure' => 'modular', 'years' => 1,
    ])->assertSessionHasNoErrors();
    $programme = (int) DB::table("{$this->cms}.classes")->where('class', 'Admissions Entry Test')->value('id');
    $year = (int) DB::table("{$this->cms}.acad_professionals")->where('class_id', $programme)->value('id');

    $this->actingAs($this->admin)->post("/setup/programmes/{$programme}/courses", [
        'code' => 'bad code!', 'title' => 'Entry Test 2026', 'professional_id' => $year,
    ])->assertSessionHasErrors('code');
    $this->actingAs($this->admin)->post("/setup/programmes/{$programme}/courses", [
        'code' => 'ENT-2026', 'title' => 'Entry Test 2026', 'professional_id' => $year,
    ])->assertSessionHasNoErrors();
    $course = (int) DB::table("{$this->cms}.acad_courses")->where('course_code', 'ENT-2026')->value('id');
    expect(DB::table("{$this->cms}.acad_courses")->where('id', $course)->value('course_kind'))->toBe('module');

    // Subjects pasted one per line; a name already there is skipped.
    $this->actingAs($this->admin)->post("/setup/courses/{$course}/curriculum", ['names' => "Biology\nChemistry\n\nBiology"])->assertSessionHasNoErrors();
    $biology = (int) DB::table("{$this->cms}.acad_curriculum_nodes")->where('course_id', $course)->where('name', 'Biology')->value('id');
    $this->actingAs($this->admin)->post("/setup/courses/{$course}/curriculum", ['parent_id' => $biology, 'names' => "Cell biology\nGenetics"])->assertSessionHasNoErrors();
    $genetics = (int) DB::table("{$this->cms}.acad_curriculum_nodes")->where('name', 'Genetics')->value('id');
    $this->actingAs($this->admin)->post("/setup/courses/{$course}/curriculum", ['parent_id' => $genetics, 'names' => 'Mendel'])->assertSessionHasNoErrors();
    $mendel = (int) DB::table("{$this->cms}.acad_curriculum_nodes")->where('name', 'Mendel')->value('id');
    // Three levels (subject → topic → subtopic): nothing goes under a subtopic.
    $this->actingAs($this->admin)->post("/setup/courses/{$course}/curriculum", ['parent_id' => $mendel, 'names' => 'Deeper'])->assertSessionHasErrors('names');

    $nodes = DB::connection('cms')->table('v_cms_curriculum_nodes')->where('course_id', $course)->orderBy('id')->get();
    expect($nodes->pluck('name')->all())->toBe(['Biology', 'Chemistry', 'Cell biology', 'Genetics', 'Mendel'])
        ->and($nodes->pluck('level_code')->unique()->values()->all())->toBe(['discipline', 'topic', 'subtopic'])
        ->and($nodes->where('name', 'Mendel')->first()->path)->toBe("/{$biology}/{$genetics}/")
        ->and($nodes->every(fn ($n) => (int) $n->allow_questions === 1))->toBeTrue();

    expect(DB::connection('cms')->table('v_cms_courses')->where('id', $course)->value('branch_id'))->toBe($this->branch);
    $this->actingAs($this->admin)->get("/setup/courses/{$course}/curriculum")->assertOk()
        ->assertInertia(fn ($page) => $page->component('setup/Curriculum')->has('nodes', 5)->has('levels', 3));
});

test('a semester programme gets two semesters a year, and its courses must name one', function () {
    $this->actingAs($this->admin)->post('/setup/programmes', [
        'name' => 'BS Computer Science', 'code' => 'BSCS', 'calendar' => 'semester', 'structure' => 'subject', 'years' => 2,
    ])->assertSessionHasNoErrors();
    $programme = (int) DB::table("{$this->cms}.classes")->where('class', 'BS Computer Science')->value('id');

    $terms = DB::connection('cms')->table('v_cms_professional_terms as t')
        ->join('v_cms_professionals as p', 'p.id', '=', 't.professional_id')->where('p.programme_id', $programme)
        ->orderBy('t.semester_no')->pluck('t.name')->all();
    expect($terms)->toBe(['Semester I', 'Semester II', 'Semester III', 'Semester IV']);

    $year = (int) DB::table("{$this->cms}.acad_professionals")->where('class_id', $programme)->where('sequence', 1)->value('id');
    $this->actingAs($this->admin)->post("/setup/programmes/{$programme}/courses", [
        'code' => 'CS-101', 'title' => 'Programming', 'professional_id' => $year,
    ])->assertSessionHasErrors('term_id');
});

test('programmes and courses of another campus cannot be reached', function () {
    $other = $this->cmsBranch('Other Campus');
    $programme = $this->cmsProgramme($other);
    $course = $this->cmsCourse($programme, $this->cmsProfessional($programme));

    $this->actingAs($this->admin)->put("/setup/programmes/{$programme}", ['name' => 'X', 'code' => 'X'])->assertNotFound();
    $this->actingAs($this->admin)->get("/setup/courses/{$course}/curriculum")->assertNotFound();
});

test('the module settings saved under Setup are the ones the module reads', function () {
    DB::table("{$this->cms}.sch_settings")->insert(['name' => 'University']);

    $this->actingAs($this->admin)->put('/setup/settings', [
        'mfa' => false, 'reviews_required' => 2, 'review_days' => 10, 'auto_activate' => true,
        'accept_stores' => false, 'academic_review' => true, 'anonymous' => true,
    ])->assertSessionHasNoErrors();

    app()->forgetScopedInstances();
    $settings = app(CmsSettings::class);
    expect($settings->reviewsRequired())->toBe(2)
        ->and($settings->reviewDays())->toBe(10)
        ->and($settings->academicReview())->toBeTrue()
        ->and($settings->reviewerAcceptStores())->toBeFalse()
        ->and($settings->reviewerAnonymous())->toBeTrue();
});
