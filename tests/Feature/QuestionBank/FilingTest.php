<?php

use App\Domain\Blueprint\BlueprintAvailability;
use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class);

/*
 * Where a question is filed, in KMU's categories (IQQUIK Phase I, step 1C): the Academic Session,
 * and — for BDS and DPT — the course as a whole when no topic is chosen. MBBS questions name a
 * subject of their module.
 */

beforeEach(function () {
    $this->shareCmsConnection();
    $this->branch = $this->cmsBranch('Main Campus');
    $this->programme = $this->cmsProgramme($this->branch, 'BDS');
    $this->professional = $this->cmsProfessional($this->programme);
    $this->course = $this->cmsCourse($this->programme, $this->professional, 'ORB');

    $role = $this->cmsRole('Faculty');
    $this->cmsGrant($role, 'qbank_questions', 'view', 'add');
    $this->author = $this->staffUser([$role], $this->branch);

    $this->olderIntake = filingIntake($this->branch, '2025 Intake', '2025-09-01');
    $this->intake = filingIntake($this->branch, '2026 Intake', '2026-09-01');
});

function filingIntake(int $branchId, string $name, string $start): int
{
    return (int) DB::table(config('database.cms_source_database').'.sessions')->insertGetId([
        'branch_id' => $branchId, 'session' => $name, 'start_date' => $start, 'is_active' => 'no',
    ]);
}

function filingQuestion(array $overrides = []): array
{
    return array_replace([
        'question_type_id' => (int) QuestionType::query()->where('code', 'single_best_answer')->value('id'),
        'course_id' => test()->course,
        'stem' => '<p>A child of 8 has a supernumerary tooth between the upper central incisors.</p>',
        'lead_in' => 'What is it called?',
        'marks' => 1,
        'negative_marks' => 0,
        'exam_type_id' => test()->cmsExamType('annual'),
        'cognitive_level_id' => 1,
        'difficulty_level_id' => 1,
        'options' => [
            ['label' => 'A', 'body' => 'Mesiodens', 'is_correct' => true, 'sort_order' => 1],
            ['label' => 'B', 'body' => 'Paramolar', 'is_correct' => false, 'sort_order' => 2],
        ],
    ], $overrides);
}

test('a BDS question can be filed on the course as a whole, with no topic', function () {
    $this->actingAs($this->author)->post('/questions', filingQuestion())->assertRedirect();

    $version = QuestionVersion::query()->latest('id')->firstOrFail();
    expect($version->course_id)->toBe($this->course)
        ->and($version->node_id)->toBeNull();

    // And it can be sent for review like any other.
    $this->actingAs($this->author)->post("/questions/{$version->question_id}/versions/{$version->id}/submit")->assertRedirect();
    expect($version->fresh()->status)->toBe(VersionStatus::Submitted);
});

test('an MBBS question names a subject of its module', function () {
    DB::table(config('database.cms_source_database').'.acad_programme_profiles')->where('class_id', $this->programme)->update(['structure_type' => 'modular']);

    $this->actingAs($this->author)->from('/questions/create')->post('/questions', filingQuestion())
        ->assertSessionHasErrors(['node_id' => 'Choose the subject of this module.']);
    $this->actingAs($this->author)->postJson('/questions/check', filingQuestion())
        ->assertJsonPath('errors.node_id.0', 'Choose the subject of this module.');

    $subject = $this->cmsCurriculumNode($this->course, $this->programme, 'Anatomy');
    $this->actingAs($this->author)->post('/questions', filingQuestion(['node_id' => $subject]))->assertRedirect();
    expect(QuestionVersion::query()->latest('id')->value('node_id'))->toBe($subject);
});

test('a question is filed under the newest Academic Session unless one is chosen', function () {
    $this->actingAs($this->author)->post('/questions', filingQuestion())->assertRedirect();
    expect(QuestionVersion::query()->latest('id')->value('intake_id'))->toBe($this->intake);

    $this->actingAs($this->author)->post('/questions', filingQuestion(['intake_id' => $this->olderIntake]))->assertRedirect();
    expect(QuestionVersion::query()->latest('id')->value('intake_id'))->toBe($this->olderIntake);

    // Another campus's session is refused.
    $elsewhere = filingIntake($this->cmsBranch('City Campus'), '2026 Intake', '2026-09-01');
    $this->actingAs($this->author)->from('/questions/create')->post('/questions', filingQuestion(['intake_id' => $elsewhere]))
        ->assertSessionHasErrors(['intake_id' => 'Choose an Academic Session of this campus.']);
});

test('the Academic Session selected in kmu-cms is the one questions are filed under', function () {
    $this->actingAs($this->author)->withSession(['cms_intake_id' => $this->olderIntake])
        ->post('/questions', filingQuestion())->assertRedirect();

    expect(QuestionVersion::query()->latest('id')->value('intake_id'))->toBe($this->olderIntake);

    $this->actingAs($this->author)->withSession(['cms_intake_id' => $this->olderIntake])->get('/questions/create')
        ->assertInertia(fn ($page) => $page->where('defaultIntakeId', $this->olderIntake)->has('intakes', 2));
});

test('the Academic Session is fixed once the question is sent for review', function () {
    $this->actingAs($this->author)->post('/questions', filingQuestion())->assertRedirect();
    $version = QuestionVersion::query()->latest('id')->firstOrFail();
    $this->actingAs($this->author)->post("/questions/{$version->question_id}/versions/{$version->id}/submit")->assertRedirect();

    expect(fn () => DB::table('qb_question_versions')->where('id', $version->id)->update(['intake_id' => $this->olderIntake]))
        ->toThrow(QueryException::class);
});

test('Save & new opens the next question where the last one was filed', function () {
    $this->actingAs($this->author)->post('/questions', [...filingQuestion(['intake_id' => $this->olderIntake]), 'then' => 'new'])
        ->assertRedirect('/questions/create?course_id='.$this->course.'&exam_type_id='.$this->cmsExamType('annual').'&intake_id='.$this->olderIntake
            .'&question_type_id='.QuestionType::query()->where('code', 'single_best_answer')->value('id'));

    $this->actingAs($this->author)->get('/questions/create?course_id='.$this->course.'&intake_id='.$this->olderIntake)
        ->assertInertia(fn ($page) => $page->where('prefill.course_id', $this->course)->where('prefill.intake_id', $this->olderIntake));
});

test('the question list can be narrowed to an Academic Session', function () {
    $this->actingAs($this->author)->post('/questions', filingQuestion(['stem' => '<p>Filed in 2025, about the mesiodens.</p>', 'intake_id' => $this->olderIntake]));
    $this->actingAs($this->author)->post('/questions', filingQuestion(['stem' => '<p>Filed in 2026, about the paramolar.</p>']));

    $this->actingAs($this->author)->get('/questions?intake_id='.$this->olderIntake)
        ->assertInertia(fn ($page) => $page->has('questions.data', 1)->where('filters.intake_id', $this->olderIntake));
});

test('a blueprint row for the whole course counts the questions filed on the course itself', function () {
    $topic = $this->cmsCurriculumNode($this->course, $this->programme, 'Tooth eruption');
    $this->actingAs($this->author)->post('/questions', filingQuestion());
    $this->actingAs($this->author)->post('/questions', filingQuestion(['node_id' => $topic, 'stem' => '<p>When does the first permanent molar erupt?</p>']));

    // Into use, one allowed step at a time.
    foreach (['submitted', 'under_review', 'approved', 'active'] as $status) {
        DB::table('qb_question_versions')->update(['status' => $status]);
    }
    foreach (DB::table('qb_question_versions')->get(['id', 'question_id']) as $row) {
        DB::table('qb_questions')->where('id', $row->question_id)->update(['active_version_id' => $row->id]);
    }

    $type = (int) QuestionType::query()->where('code', 'single_best_answer')->value('id');
    $matrix = app(BlueprintAvailability::class)->matrix($this->branch, $this->course, $this->cmsExamType('annual'));

    expect($matrix[0][$type])->toBe(2)
        ->and($matrix[$topic][$type])->toBe(1);
});
