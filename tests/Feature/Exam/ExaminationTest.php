<?php

use App\Domain\Exam\Models\Examination;
use App\Domain\Identity\Authorization\AccessControl;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsExaminations;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class, BuildsExaminations::class);

beforeEach(function () {
    $this->examWorld();
});

test('an examiner sets up an examination for a course they may set', function () {
    $this->actingAs($this->setter)->post('/exams', $this->examPayload())->assertRedirect();

    $exam = Examination::query()->firstOrFail();

    expect($exam->public_ref)->toMatch('/^EX-\d{4}-\d{4}$/')
        ->and($exam->branch_id)->toBe($this->branch)
        // Programme and year come from the course, so they cannot disagree with it.
        ->and($exam->programme_id)->toBe($this->programme)
        ->and($exam->professional_id)->toBe($this->professional)
        ->and($exam->course_id)->toBe($this->course)
        ->and($exam->exam_type_id)->toBe($this->annual)
        ->and($exam->duration_minutes)->toBe(180)
        ->and($exam->total_marks)->toBe(100.0)
        ->and($exam->pass_percentage)->toBe(50.0)
        ->and($exam->negative_marking)->toBeFalse()
        ->and($exam->status->value)->toBe('draft')
        ->and($exam->created_by)->toBe($this->setter->id);

    // It starts with an empty blueprint in preparation.
    expect(DB::table('exm_blueprints')->where('examination_id', $exam->id)->value('status'))->toBe('draft');
    expect(DB::table('sec_audit_logs')->where('action', 'exam.created')->where('entity_id', (string) $exam->id)->where('branch_id', $this->branch)->exists())->toBeTrue();
});

test('references are handed out one at a time', function () {
    $first = $this->newExam();
    $second = $this->newExam();

    expect($first->public_ref)->not->toBe($second->public_ref)
        ->and((int) substr($second->public_ref, -4))->toBe((int) substr($first->public_ref, -4) + 1);
});

test('the date and time are entered in the examination time zone and stored in UTC', function () {
    config(['exam.timezone' => 'Asia/Karachi']);
    $exam = $this->newExam(['starts_at' => '2026-10-05T09:00']);

    // 09:00 in Karachi (UTC+5) is 04:00 UTC.
    expect($exam->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-05 04:00');

    $this->actingAs($this->setter)->get("/exams/{$exam->id}/edit")->assertInertia(fn ($page) => $page
        ->component('exams/ExamForm')
        ->where('examination.startsAt', '2026-10-05T09:00')
        ->where('examination.startsAtLabel', 'Mon 5 Oct 2026, 09:00'));
});

test('an examination without a title is named the way the university names it', function () {
    $exam = $this->newExam(['title' => '']);

    expect($exam->title)->toContain('Examination 2026')->toContain('Annual')->toContain('CVS-');
});

test('the details are checked and every error names its field', function () {
    $bad = fn (array $overrides) => $this->actingAs($this->setter)->from('/exams/create')->post('/exams', $this->examPayload($overrides));

    $bad(['duration_minutes' => 2])->assertSessionHasErrors('duration_minutes');
    $bad(['duration_minutes' => 5000])->assertSessionHasErrors('duration_minutes');
    $bad(['total_marks' => 0])->assertSessionHasErrors('total_marks');
    $bad(['pass_percentage' => 101])->assertSessionHasErrors('pass_percentage');
    $bad(['negative_fraction' => 2])->assertSessionHasErrors('negative_fraction');
    $bad(['starts_at' => 'tomorrow'])->assertSessionHasErrors('starts_at');
    $bad(['course_id' => null])->assertSessionHasErrors('course_id');
    $bad(['exam_type_id' => null])->assertSessionHasErrors('exam_type_id');

    expect(Examination::query()->count())->toBe(0);
});

test('the examination has to be one the programme holds', function () {
    // The test programme runs on the annual calendar: Regular and Retake are for semester programmes.
    $this->actingAs($this->setter)->from('/exams/create')
        ->post('/exams', $this->examPayload(['exam_type_id' => $this->cmsExamType('regular')]))
        ->assertSessionHasErrors('exam_type_id');

    $this->actingAs($this->setter)->post('/exams', $this->examPayload(['exam_type_id' => $this->cmsExamType('supplementary')]))
        ->assertSessionHasNoErrors();
});

test('a course of another campus, or one that is not in use, cannot be chosen', function () {
    $other = $this->cmsBranch('City Campus');
    $otherProgramme = $this->cmsProgramme($other, 'DPT');
    $elsewhere = $this->cmsCourse($otherProgramme, $this->cmsProfessional($otherProgramme), 'PHY');
    $retired = $this->cmsCourse($this->programme, $this->professional, 'OLD');
    DB::table(config('database.cms_source_database').'.acad_courses')->where('id', $retired)->update(['status' => 'retired']);

    $this->actingAs($this->setter)->from('/exams/create')->post('/exams', $this->examPayload(['course_id' => $elsewhere]))->assertSessionHasErrors('course_id');
    $this->actingAs($this->setter)->from('/exams/create')->post('/exams', $this->examPayload(['course_id' => $retired]))->assertSessionHasErrors('course_id');

    expect(Examination::query()->count())->toBe(0);
});

test('an academic session must be one of the campus\'s', function () {
    $cms = config('database.cms_source_database');
    $mine = DB::table("{$cms}.sessions")->insertGetId(['branch_id' => $this->branch, 'session' => '2026 Intake', 'is_active' => 'no']);
    $other = DB::table("{$cms}.sessions")->insertGetId(['branch_id' => $this->cmsBranch('City Campus'), 'session' => '2026 Intake City', 'is_active' => 'no']);

    $this->actingAs($this->setter)->from('/exams/create')->post('/exams', $this->examPayload(['intake_id' => $other]))->assertSessionHasErrors('intake_id');
    $this->actingAs($this->setter)->post('/exams', $this->examPayload(['intake_id' => $mine]))->assertSessionHasNoErrors();

    expect(Examination::query()->value('intake_id'))->toBe($mine);
});

test('negative marking keeps its fraction only while it is on', function () {
    $on = $this->newExam(['negative_marking' => true, 'negative_fraction' => 0.25]);
    $off = $this->newExam(['negative_marking' => false, 'negative_fraction' => 0.25]);

    expect($on->negative_marking)->toBeTrue()->and($on->negative_fraction)->toBe(0.25)
        ->and($off->negative_marking)->toBeFalse()->and($off->negative_fraction)->toBeNull();
});

test('what a person sees follows the CMS role', function () {
    $exam = $this->newExam();

    // Somebody with no examination right at all.
    $none = $this->staffUser([$this->cmsRole('Cleaner')], $this->branch);
    $this->actingAs($none)->get('/exams')->assertForbidden();
    $this->actingAs($none)->get("/exams/{$exam->id}")->assertForbidden();

    // Blueprint viewing alone is enough to see the list, but not to create.
    $reader = $this->staffUser([$this->approverRole], $this->branch);
    $this->actingAs($reader)->get('/exams')->assertOk()->assertInertia(fn ($page) => $page
        ->where('canCreate', false)
        ->where('auth.can.viewExams', true)
        ->where('auth.can.createExams', false));
    $this->actingAs($reader)->get('/exams/create')->assertForbidden();
    $this->actingAs($reader)->post('/exams', $this->examPayload())->assertForbidden();
    $this->actingAs($reader)->get("/exams/{$exam->id}/edit")->assertForbidden();

    $this->actingAs($this->setter)->get('/exams')->assertInertia(fn ($page) => $page
        ->where('canCreate', true)
        ->where('auth.can.createExams', true)
        ->where('examinations.total', 1));
});

test('exam access limits and campuses hide the examinations of other places', function () {
    $exam = $this->newExam();

    // Limited to another programme: the examination is not listed and not reachable.
    $limited = $this->staffUser([$this->setterRole], $this->branch);
    $this->cmsExamScope($limited, 'programme', $this->cmsProgramme($this->branch, 'BDS'));
    $this->actingAs($limited)->get('/exams')->assertInertia(fn ($page) => $page->where('examinations.total', 0));
    $this->actingAs($limited)->get("/exams/{$exam->id}")->assertForbidden();
    $this->actingAs($limited)->post('/exams', $this->examPayload())->assertForbidden();

    // Limited to this programme: everything of it.
    $mine = $this->staffUser([$this->setterRole], $this->branch);
    $this->cmsExamScope($mine, 'programme', $this->programme);
    $this->actingAs($mine)->get('/exams')->assertInertia(fn ($page) => $page->where('examinations.total', 1));

    // Another campus does not see it at all.
    $elsewhere = $this->staffUser([$this->setterRole], $this->cmsBranch('City Campus'));
    app(AccessControl::class)->forget($elsewhere);
    $this->actingAs($elsewhere)->get("/exams/{$exam->id}")->assertNotFound();
    $this->actingAs($elsewhere)->get('/exams')->assertInertia(fn ($page) => $page->where('examinations.total', 0));
});

test('the list filters by programme, examination, course, stage and words', function () {
    $first = $this->newExam(['title' => 'Cardiac paper']);
    $second = $this->newExam(['title' => 'Renal paper', 'exam_type_id' => $this->cmsExamType('supplementary')]);

    $get = fn (string $query) => $this->actingAs($this->setter)->get('/exams'.$query);

    $get('?search=Renal')->assertInertia(fn ($page) => $page->where('examinations.total', 1)->where('examinations.data.0.reference', $second->public_ref));
    $get('?search='.$first->public_ref)->assertInertia(fn ($page) => $page->where('examinations.total', 1));
    $get('?exam_type_id='.$this->annual)->assertInertia(fn ($page) => $page->where('examinations.total', 1)->where('examinations.data.0.title', 'Cardiac paper'));
    $get('?programme_id='.$this->programme.'&course_id='.$this->course)->assertInertia(fn ($page) => $page->where('examinations.total', 2));
    $get('?year='.$this->professional)->assertInertia(fn ($page) => $page->where('examinations.total', 2));
    $get('?stage=approved')->assertInertia(fn ($page) => $page->where('examinations.total', 0));
    $get('?stage=draft')->assertInertia(fn ($page) => $page->where('examinations.total', 2)
        ->where('stages', fn ($stages) => collect($stages)->pluck('count', 'key')->all() === ['draft' => 2, 'submitted' => 0, 'approved' => 0]));
    $get('?stage=nonsense')->assertSessionHasErrors('stage');
});

test('the details can be changed while the blueprint is in preparation', function () {
    $exam = $this->newExam();
    $other = $this->cmsCourse($this->programme, $this->professional, 'RESP');

    $this->actingAs($this->setter)->put("/exams/{$exam->id}", $this->examPayload([
        'title' => 'Renamed', 'course_id' => $other, 'total_marks' => 80, 'duration_minutes' => 120,
    ]))->assertRedirect("/exams/{$exam->id}");

    $exam->refresh();
    expect($exam->title)->toBe('Renamed')->and($exam->course_id)->toBe($other)
        ->and($exam->total_marks)->toBe(80.0)->and($exam->duration_minutes)->toBe(120);

    $changed = DB::table('sec_audit_logs')->where('action', 'exam.updated')->where('entity_id', (string) $exam->id)->value('new_values');
    expect(json_decode((string) $changed, true))->toHaveKeys(['title', 'course_id', 'total_marks', 'duration_minutes']);
});

test('an examination with rows in its blueprint cannot move to another course', function () {
    $exam = $this->newExam();
    $other = $this->cmsCourse($this->programme, $this->professional, 'RESP');
    DB::table('exm_blueprint_rows')->insert([
        'blueprint_id' => $exam->blueprint->id, 'sort_order' => 1, 'node_id' => $this->node,
        'question_type_id' => (int) DB::table('qb_question_types')->value('id'), 'question_count' => 5, 'marks_each' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->setter)->from("/exams/{$exam->id}/edit")->put("/exams/{$exam->id}", $this->examPayload(['course_id' => $other]))
        ->assertSessionHasErrors('course_id');

    expect($exam->fresh()->course_id)->toBe($this->course);
});

test('once the blueprint is submitted the course, examination and total marks are fixed', function () {
    $exam = $this->newExam();
    DB::table('exm_blueprints')->where('examination_id', $exam->id)->update(['status' => 'submitted']);
    $other = $this->cmsCourse($this->programme, $this->professional, 'RESP');

    foreach ([['course_id' => $other], ['total_marks' => 90], ['exam_type_id' => $this->cmsExamType('supplementary')]] as $change) {
        $this->actingAs($this->setter)->from("/exams/{$exam->id}/edit")->put("/exams/{$exam->id}", $this->examPayload($change))
            ->assertSessionHasErrors('course_id');
    }

    // What does not touch the blueprint can still be corrected.
    $this->actingAs($this->setter)->put("/exams/{$exam->id}", $this->examPayload(['title' => 'Corrected title', 'duration_minutes' => 150, 'pass_percentage' => 55]))
        ->assertRedirect("/exams/{$exam->id}");
    expect($exam->fresh()->title)->toBe('Corrected title')->and($exam->fresh()->duration_minutes)->toBe(150);

    // And the database refuses the rest even without the application.
    expect(fn () => DB::table('exm_examinations')->where('id', $exam->id)->update(['total_marks' => 90]))
        ->toThrow(QueryException::class, 'cannot be changed');
});

test('an examination past the blueprint stage is never deleted', function () {
    $exam = $this->newExam();

    DB::table('exm_examinations')->where('id', $exam->id)->update(['status' => 'blueprint_approved']);
    expect(fn () => DB::table('exm_examinations')->where('id', $exam->id)->delete())->toThrow(QueryException::class, 'never deleted');

    DB::table('exm_blueprints')->where('examination_id', $exam->id)->update(['status' => 'submitted']);
    expect(fn () => DB::table('exm_blueprints')->where('examination_id', $exam->id)->delete())->toThrow(QueryException::class, 'never deleted');
});

test('the workspace and the form are given what they need', function () {
    $exam = $this->newExam(['negative_marking' => true, 'negative_fraction' => 0.25]);

    $this->actingAs($this->setter)->get("/exams/{$exam->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('exams/Show')
        ->where('examination.reference', $exam->public_ref)
        ->where('examination.totalMarks', 100)
        ->where('examination.passMarks', 50)
        ->where('examination.negativeFraction', 0.25)
        ->where('blueprint.status', 'draft')
        ->where('can.edit', true)
        ->where('can.editBlueprint', true)
        ->where('can.approve', false));

    $this->actingAs($this->setter)->get('/exams/create')->assertOk()->assertInertia(fn ($page) => $page
        ->component('exams/ExamForm')
        ->where('examination', null)
        ->where('courses.0.id', $this->course)
        ->where('programmes.0.id', $this->programme)
        ->where('defaults.durationMinutes', 180)
        ->where('timezone', 'Asia/Karachi'));
});

test('guests are sent away and the routes need the permission', function () {
    $exam = $this->newExam();
    auth()->logout();

    $this->get('/exams')->assertRedirect();
    $this->get("/exams/{$exam->id}")->assertRedirect();
    $this->post('/exams', $this->examPayload())->assertRedirect();

    expect(User::query()->count())->toBeGreaterThan(0);
});
