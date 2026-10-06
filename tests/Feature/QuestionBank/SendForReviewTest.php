<?php

use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class);

/*
 * "Send for review" sends what is on the screen: it saves the draft first, so a question that has
 * not been saved yet, or one with unsaved changes, goes for review as the author sees it. References
 * and the explanation are optional and never stop it.
 */

beforeEach(function () {
    $this->shareCmsConnection();
    $this->cmsExamSettings();
    $this->branch = $this->cmsBranch('Main Campus');
    $this->programme = $this->cmsProgramme($this->branch, 'BDS');
    $this->professional = $this->cmsProfessional($this->programme);
    $this->course = $this->cmsCourse($this->programme, $this->professional, 'ORB');

    $role = $this->cmsRole('Faculty');
    $this->cmsGrant($role, 'qbank_questions', 'view', 'add');
    $this->author = $this->staffUser([$role], $this->branch);
});

function readyQuestion(array $overrides = []): array
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

test('a question not saved yet is saved and sent for review in one step', function () {
    $this->actingAs($this->author)->post('/questions', [...readyQuestion(), 'then' => 'submit'])
        ->assertRedirect('/questions');

    expect(QuestionVersion::query()->latest('id')->firstOrFail()->status)->toBe(VersionStatus::Submitted);
});

test('unsaved changes are saved before the draft goes for review', function () {
    $this->actingAs($this->author)->post('/questions', readyQuestion(['exam_type_id' => null]));
    $version = QuestionVersion::query()->latest('id')->firstOrFail();

    // The saved draft has no examination type; the screen now has one. What is sent is the screen.
    $this->actingAs($this->author)->put("/questions/{$version->question_id}/versions/{$version->id}", [...readyQuestion(['stem' => '<p>The changed question text, about a mesiodens.</p>']), 'then' => 'submit'])
        ->assertRedirect('/questions');

    $version->refresh();
    expect($version->status)->toBe(VersionStatus::Submitted)
        ->and($version->stem)->toContain('changed question text');
});

test('when something still stops it, the draft is kept and the editor says why', function () {
    $this->actingAs($this->author)->post('/questions', [...readyQuestion(['exam_type_id' => null]), 'then' => 'submit'])
        ->assertSessionHasErrors('exam_type_id');

    $version = QuestionVersion::query()->latest('id')->firstOrFail();
    expect($version->status)->toBe(VersionStatus::Draft);
});

test('references and the explanation are optional, and an empty reference line is ignored', function () {
    $this->actingAs($this->author)->post('/questions', [...readyQuestion([
        'explanation' => null,
        'references' => [['kind' => 'book', 'citation' => '   ', 'sort_order' => 1]],
    ]), 'then' => 'submit'])->assertSessionHasNoErrors()->assertRedirect('/questions');

    $version = QuestionVersion::query()->latest('id')->firstOrFail();
    expect($version->status)->toBe(VersionStatus::Submitted)
        ->and($version->references()->count())->toBe(0);

    $checks = $this->actingAs($this->author)->postJson('/questions/check', readyQuestion())->assertOk()->json();
    expect($checks['errors'])->toBe([])
        ->and(collect($checks['warnings'])->filter(fn (string $w): bool => str_contains($w, 'reference') || str_contains($w, 'explanation'))->all())->toBe([]);
});
