<?php

use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class);

beforeEach(function () {
    $this->shareCmsConnection();
    $this->branch = $this->cmsBranch('Main Campus');
    $this->programme = $this->cmsProgramme($this->branch, 'MBBS');
    $this->professional = $this->cmsProfessional($this->programme);
    $this->course = $this->cmsCourse($this->programme, $this->professional, 'CVS');
    $this->node = $this->cmsCurriculumNode($this->course, $this->programme);
});

test('the dashboard shows the campus question bank and links to it', function () {
    $role = $this->cmsRole('Faculty');
    $this->cmsGrant($role, 'qbank_questions', 'view', 'add');
    $author = $this->staffUser([$role], $this->branch);

    $this->actingAs($author)->post('/questions', [
        'question_type_id' => (int) QuestionType::query()->where('code', 'single_best_answer')->value('id'),
        'course_id' => $this->course,
        'node_id' => $this->node,
        'stem' => '<p>A 54-year-old man has crushing chest pain radiating to the jaw.</p>',
        'marks' => 1,
        'negative_marks' => 0,
        'options' => [
            ['label' => 'A', 'body' => 'ECG', 'is_correct' => true, 'sort_order' => 1],
            ['label' => 'B', 'body' => 'Chest radiograph', 'is_correct' => false, 'sort_order' => 2],
        ],
    ])->assertRedirect();

    $this->actingAs($author)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->where('questionBank.visible', true)
        ->where('questionBank.total', 1)
        ->where('questionBank.mine', 1)
        ->where('questionBank.courses', 1)
        ->where('questionBank.statuses.0', ['status' => 'draft', 'label' => 'Draft', 'count' => 1])
        ->where('auth.can.viewQuestions', true)
        ->where('auth.can.createQuestions', true));

    expect(QuestionVersion::query()->count())->toBe(1);
});

test('staff with no exam permission see the dashboard without the question bank', function () {
    $user = $this->staffUser([$this->cmsRole('Receptionist')], $this->branch);

    $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
        ->where('questionBank.visible', false)
        ->where('questionBank.total', 0)
        ->where('auth.can.viewQuestions', false));
});
