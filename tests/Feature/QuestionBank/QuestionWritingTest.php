<?php

use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

    $this->author = function (array $permissions = ['qbank_questions' => ['view', 'add']]): User {
        $role = $this->cmsRole('Faculty '.bin2hex(random_bytes(3)));
        foreach ($permissions as $category => $checkboxes) {
            $this->cmsGrant($role, $category, ...$checkboxes);
        }

        return $this->staffUser([$role], $this->branch);
    };
});

function typeId(string $code): int
{
    return (int) QuestionType::query()->where('code', $code)->value('id');
}

/**
 * A complete single-best-answer question as the editor sends it.
 */
function sba(array $overrides = []): array
{
    return array_replace([
        'question_type_id' => typeId('single_best_answer'),
        'course_id' => test()->course,
        'node_id' => test()->node,
        'stem' => '<p>A 54-year-old man has crushing chest pain radiating to the jaw for 40 minutes.</p>',
        'lead_in' => 'Which investigation is most useful first?',
        'explanation' => '<p>An ECG is immediate and guides reperfusion.</p>',
        'marks' => 1,
        'negative_marks' => 0,
        'exam_type_id' => test()->cmsExamType('annual'),
        'difficulty_level_id' => 2,
        'cognitive_level_id' => 3,
        'options' => [
            ['label' => 'A', 'body' => 'ECG', 'is_correct' => true, 'sort_order' => 1],
            ['label' => 'B', 'body' => 'Chest radiograph', 'is_correct' => false, 'sort_order' => 2],
            ['label' => 'C', 'body' => 'Echocardiogram', 'is_correct' => false, 'sort_order' => 3],
            ['label' => 'D', 'body' => 'Coronary angiography', 'is_correct' => false, 'sort_order' => 4],
        ],
        'references' => [
            ['kind' => 'book', 'citation' => 'Harrison, Principles of Internal Medicine', 'locator' => '21st ed, p. 1875', 'sort_order' => 1],
        ],
    ], $overrides);
}

test('an author writes a question in the campus and course they work in', function () {
    $author = ($this->author)();

    $this->actingAs($author)->post('/questions', sba())->assertRedirect();

    $question = Question::query()->firstOrFail();
    $version = $question->versions()->firstOrFail();

    expect($question->public_ref)->toMatch('/^Q-\d{4}-\d{6}$/')
        ->and($question->branch_id)->toBe($this->branch)
        ->and($question->course_id)->toBe($this->course)
        ->and($version->status)->toBe(VersionStatus::Draft)
        ->and($version->version_no)->toBe(1)
        ->and($version->programme_id)->toBe($this->programme)
        ->and($version->professional_id)->toBe($this->professional)
        ->and($version->node_id)->toBe($this->node)
        ->and($version->author_id)->toBe($author->id)
        ->and($version->options()->count())->toBe(4)
        ->and($version->options()->where('is_correct', true)->value('label'))->toBe('A')
        ->and($version->references()->count())->toBe(1)
        ->and($version->search_text)->toContain('crushing chest pain')
        ->and($version->content_hash)->toHaveLength(64);

    expect(DB::table('sec_audit_logs')->where('action', 'qbank.question.created')->where('branch_id', $this->branch)->exists())->toBeTrue();
});

test('question text is cleaned: scripts, event handlers and javascript links never reach the bank', function () {
    $author = ($this->author)();

    $this->actingAs($author)->post('/questions', sba([
        'stem' => '<p onclick="steal()">Which drug is first line?<script>alert(1)</script></p><iframe src="http://evil"></iframe>',
        'explanation' => '<p><a href="javascript:alert(1)">explanation</a></p>',
        'options' => [
            ['label' => 'A', 'body' => '<b>Aspirin</b><img src="x" onerror="alert(1)">', 'is_correct' => true, 'sort_order' => 1],
            ['label' => 'B', 'body' => 'Warfarin', 'is_correct' => false, 'sort_order' => 2],
        ],
    ]))->assertRedirect();

    $version = QuestionVersion::query()->firstOrFail();

    expect($version->stem)->not->toContain('script')->not->toContain('onclick')->not->toContain('iframe')
        ->and($version->stem)->toContain('Which drug is first line?')
        ->and($version->explanation)->not->toContain('javascript:')
        ->and($version->options()->where('label', 'A')->value('body'))->toContain('<b>Aspirin</b>')
        ->and($version->options()->where('label', 'A')->value('body'))->not->toContain('onerror');
});

test('a question cannot be sent for review until it satisfies its type', function () {
    $author = ($this->author)(['qbank_questions' => ['view', 'add']]);

    $this->actingAs($author)->post('/questions', sba([
        'options' => [
            ['label' => 'A', 'body' => 'ECG', 'is_correct' => false, 'sort_order' => 1],
            ['label' => 'B', 'body' => 'ecg', 'is_correct' => false, 'sort_order' => 2],
        ],
    ]));
    $version = QuestionVersion::query()->firstOrFail();

    $this->actingAs($author)->from('/questions')->post("/questions/{$version->question_id}/versions/{$version->id}/submit")
        ->assertSessionHasErrors(['options']);
    expect($version->fresh()->status)->toBe(VersionStatus::Draft);

    $this->actingAs($author)->put("/questions/{$version->question_id}/versions/{$version->id}", sba())->assertSessionHasNoErrors();
    $this->actingAs($author)->post("/questions/{$version->question_id}/versions/{$version->id}/submit", ['note' => 'Ready'])
        ->assertRedirect('/questions');

    $version->refresh();
    expect($version->status)->toBe(VersionStatus::Submitted)
        ->and($version->submitted_at)->not->toBeNull()
        ->and($version->statusLog()->first()->to_status)->toBe(VersionStatus::Submitted)
        ->and($version->statusLog()->first()->reason)->toBe('Ready')
        ->and(DB::table('sec_audit_logs')->where('action', 'qbank.version.submitted')->exists())->toBeTrue();
});

test('each kind of question is checked against its own rules', function () {
    $author = ($this->author)();
    $check = fn (array $payload): array => $this->actingAs($author)->postJson('/questions/check', $payload)->json();

    // Multiple true/false: every statement needs an answer.
    $mtf = sba(['question_type_id' => typeId('multiple_true_false'), 'options' => [], 'items' => [
        ['body' => 'Aspirin reduces mortality', 'is_true' => true, 'sort_order' => 1],
        ['body' => 'Streptokinase is first line', 'sort_order' => 2],
    ]]);
    expect($check($mtf)['errors'])->toHaveKey('items.1');

    // Extended matching: each lead-in must point at one of the shared options.
    $emq = sba(['question_type_id' => typeId('extended_matching'), 'options' => [
        ['label' => 'A', 'body' => 'Myocardial infarction', 'sort_order' => 1],
        ['label' => 'B', 'body' => 'Pericarditis', 'sort_order' => 2],
        ['label' => 'C', 'body' => 'Aortic dissection', 'sort_order' => 3],
        ['label' => 'D', 'body' => 'Pulmonary embolism', 'sort_order' => 4],
    ], 'items' => [
        ['body' => 'Tearing pain to the back', 'correct_option_label' => 'C', 'sort_order' => 1],
        ['body' => 'Pain relieved by sitting forward', 'correct_option_label' => 'Z', 'sort_order' => 2],
    ]]);
    expect($check($emq)['errors'])->toHaveKey('items.1')
        ->and($check($emq)['errors'])->not->toHaveKey('items.0');

    // Short answer: at least one accepted answer.
    $short = sba(['question_type_id' => typeId('short_answer'), 'options' => []]);
    expect($check($short)['errors'])->toHaveKey('answers');
    $short['answers'] = [['match_mode' => 'exact', 'answer_text' => 'aspirin', 'marks_fraction' => 1, 'tolerance_type' => 'absolute', 'sort_order' => 1]];
    expect($check($short)['errors'])->toBe([]);

    // Numerical: a number and a tolerance that is not negative.
    $numeric = sba(['question_type_id' => typeId('numerical'), 'options' => [], 'answers' => [
        ['match_mode' => 'numeric', 'numeric_value' => null, 'tolerance' => 0.5, 'marks_fraction' => 1, 'tolerance_type' => 'absolute', 'sort_order' => 1],
    ]]);
    expect($check($numeric)['errors'])->toHaveKey('answers.0');
    $numeric['answers'][0]['numeric_value'] = 7.35;
    expect($check($numeric)['errors'])->toBe([]);

    // Essay: marked by a person, so no negative marks and a rubric is allowed.
    $essay = sba(['question_type_id' => typeId('essay'), 'options' => [], 'negative_marks' => 1, 'marks' => 10, 'rubric' => [
        ['criterion' => 'Mentions reperfusion within 90 minutes', 'max_marks' => 5, 'sort_order' => 1],
    ]]);
    expect($check($essay)['errors'])->toHaveKey('negative_marks');
    $essay['negative_marks'] = 0;
    expect($check($essay)['errors'])->toBe([]);

    // Options are not allowed where the type has none.
    expect($check(sba(['question_type_id' => typeId('essay')]))['errors'])->toHaveKey('options');
});

test('the item-writing checklist warns without blocking', function () {
    $author = ($this->author)();

    $result = $this->actingAs($author)->postJson('/questions/check', sba([
        'lead_in' => 'Which of the following is NOT a feature?',
        'explanation' => null,
        'references' => [],
        'options' => [
            ['label' => 'A', 'body' => 'All of the above', 'is_correct' => true, 'sort_order' => 1],
            ['label' => 'B', 'body' => 'Never seen in children', 'is_correct' => false, 'sort_order' => 2],
            ['label' => 'C', 'body' => 'Fever', 'is_correct' => false, 'sort_order' => 3],
        ],
    ]))->json();

    expect($result['errors'])->toBe([])
        ->and(implode(' ', $result['warnings']))
        ->toContain('all of the above')
        ->toContain('NOT true')
        ->toContain('explanation')
        ->toContain('reference');
});

test('a question is filed under an examination and judged for its level before it can go for review', function () {
    $author = ($this->author)();

    $result = $this->actingAs($author)->postJson('/questions/check', sba([
        'exam_type_id' => null,
        'cognitive_level_id' => null,
        'difficulty_level_id' => null,
    ]))->json();

    expect($result['errors']['exam_type_id'][0])->toContain('examination type')
        ->and($result['errors']['cognitive_level_id'][0])->toContain('Recall, Understanding, Application or Analysis')
        ->and($result['errors']['difficulty_level_id'][0])->toContain('Easy, Moderate or Difficult');

    // Annual and Supplementary belong to annual programmes; Regular and Retake to semester ones.
    $this->actingAs($author)->from('/questions/create')
        ->post('/questions', sba(['exam_type_id' => $this->cmsExamType('regular')]))
        ->assertSessionHasErrors('exam_type_id');
    $this->actingAs($author)->post('/questions', sba(['exam_type_id' => $this->cmsExamType('supplementary')]))
        ->assertSessionHasNoErrors();
});

test('writing needs the permission, the campus and the exam access', function () {
    $reader = ($this->author)(['qbank_questions' => ['view']]);
    $this->actingAs($reader)->get('/questions/create')->assertForbidden();
    $this->actingAs($reader)->post('/questions', sba())->assertForbidden();

    // Exam access limited to another programme in kmu-cms.
    $limited = ($this->author)();
    $otherProgramme = $this->cmsProgramme($this->branch, 'BDS');
    $this->cmsExamScope($limited, 'programme', $otherProgramme);
    $this->actingAs($limited)->post('/questions', sba())->assertForbidden();

    // A course in another campus.
    $otherBranch = $this->cmsBranch('City Campus');
    $otherProgrammeInCity = $this->cmsProgramme($otherBranch, 'DPT');
    $otherCourse = $this->cmsCourse($otherProgrammeInCity, $this->cmsProfessional($otherProgrammeInCity), 'PHY');
    $author = ($this->author)();
    $this->actingAs($author)->from('/questions/create')->post('/questions', sba(['course_id' => $otherCourse]))->assertSessionHasErrors('node_id');

    expect(Question::query()->count())->toBe(0);
});

test('only the author, or someone allowed to edit any question, can change a draft', function () {
    $author = ($this->author)();
    $this->actingAs($author)->post('/questions', sba());
    $version = QuestionVersion::query()->firstOrFail();

    $colleague = ($this->author)(['qbank_questions' => ['view', 'add']]);
    $this->actingAs($colleague)->get("/questions/{$version->question_id}/versions/{$version->id}/edit")->assertForbidden();
    $this->actingAs($colleague)->put("/questions/{$version->question_id}/versions/{$version->id}", sba(['marks' => 3]))->assertForbidden();

    $editor = ($this->author)(['qbank_questions' => ['view', 'add', 'edit']]);
    $this->actingAs($editor)->put("/questions/{$version->question_id}/versions/{$version->id}", sba(['marks' => 3]))->assertSessionHasNoErrors();

    expect($version->fresh()->marks)->toBe(3.0)
        ->and($version->fresh()->updated_by)->toBe($editor->id);
});

test('a question of another campus is not reachable', function () {
    $author = ($this->author)();
    $this->actingAs($author)->post('/questions', sba());
    $version = QuestionVersion::query()->firstOrFail();

    DB::table('qb_questions')->where('id', $version->question_id)->update(['branch_id' => $this->cmsBranch('City Campus')]);

    $this->actingAs($author)->get("/questions/{$version->question_id}/versions/{$version->id}/edit")->assertNotFound();
    $this->actingAs($author)->get('/questions')->assertInertia(fn ($page) => $page->where('questions.total', 0));
});

test('a submitted question is not edited: the next version is a draft copied from it', function () {
    $author = ($this->author)(['qbank_questions' => ['view', 'add', 'edit']]);
    $this->actingAs($author)->post('/questions', sba());
    $version = QuestionVersion::query()->firstOrFail();
    $this->actingAs($author)->post("/questions/{$version->question_id}/versions/{$version->id}/submit");

    $this->actingAs($author)->from('/questions')->put("/questions/{$version->question_id}/versions/{$version->id}", sba(['marks' => 2]))
        ->assertSessionHasErrors('status');

    // A new version can only start once the last one is no longer a draft.
    $this->actingAs($author)->post("/questions/{$version->question_id}/versions")->assertRedirect();
    $second = QuestionVersion::query()->where('version_no', 2)->firstOrFail();

    expect($second->status)->toBe(VersionStatus::Draft)
        ->and($second->stem)->toBe($version->stem)
        ->and($second->options()->count())->toBe($version->options()->count())
        ->and($second->question->latest_version_no)->toBe(2);

    $this->actingAs($author)->from('/questions')->post("/questions/{$version->question_id}/versions")->assertSessionHasErrors('question');
    expect(QuestionVersion::query()->count())->toBe(2);
});

test('the topic must belong to the course and allow questions', function () {
    $author = ($this->author)();
    $otherCourse = $this->cmsCourse($this->programme, $this->professional, 'RESP');
    $otherNode = $this->cmsCurriculumNode($otherCourse, $this->programme, 'Asthma');
    $noQuestionsNode = $this->cmsCurriculumNode($this->course, $this->programme, 'Module overview', allowQuestions: false);

    $this->actingAs($author)->from('/questions/create')->post('/questions', sba(['node_id' => $otherNode]))->assertSessionHasErrors('node_id');
    $this->actingAs($author)->from('/questions/create')->post('/questions', sba(['node_id' => $noQuestionsNode]))->assertSessionHasErrors('node_id');

    expect(Question::query()->count())->toBe(0);
});

test('the editor asks for the programme first, then only its courses', function () {
    $author = ($this->author)();
    $bds = $this->cmsProgramme($this->branch, 'BDS');
    $bdsCourse = $this->cmsCourse($bds, $this->cmsProfessional($bds), 'ORAL');
    $otherBranch = $this->cmsBranch('City Campus');
    $cityProgramme = $this->cmsProgramme($otherBranch, 'DPT');
    $this->cmsCourse($cityProgramme, $this->cmsProfessional($cityProgramme), 'PHY');

    $this->actingAs($author)->get('/questions/create')->assertOk()->assertInertia(fn ($page) => $page
        // Programmes of this campus that the author has a course in, and nothing else.
        ->where('programmes', fn ($programmes) => collect($programmes)->pluck('id')->sort()->values()->all() === collect([$this->programme, $bds])->sort()->values()->all())
        ->where('courses', fn ($courses) => collect($courses)->pluck('id')->sort()->values()->all() === collect([$this->course, $bdsCourse])->sort()->values()->all())
        ->where('courses', fn ($courses) => collect($courses)->firstWhere('id', $bdsCourse)['programme_id'] === $bds));
});

test('a programme the author has no course in is not offered', function () {
    $role = $this->cmsRole('Faculty limited');
    $this->cmsGrant($role, 'qbank_questions', 'view', 'add');
    $author = $this->staffUser([$role], $this->branch);
    $this->cmsExamScope($author, 'course', $this->course);
    $bds = $this->cmsProgramme($this->branch, 'BDS');
    $this->cmsCourse($bds, $this->cmsProfessional($bds), 'ORAL');

    $this->actingAs($author)->get('/questions/create')->assertInertia(fn ($page) => $page
        ->where('programmes', fn ($programmes) => collect($programmes)->pluck('id')->all() === [$this->programme])
        ->where('courses', fn ($courses) => collect($courses)->pluck('id')->all() === [$this->course]));
});

test('the editor is given the types, courses, topics and lookups it needs', function () {
    $author = ($this->author)();

    $this->actingAs($author)->get('/questions/create')->assertOk()->assertInertia(fn ($page) => $page
        ->component('qbank/QuestionEditor')
        ->where('version', null)
        ->where('types', fn ($types) => count($types) === 12 && collect($types)->firstWhere('code', 'single_best_answer')['optionsMax'] === 10)
        ->where('courses', fn ($courses) => collect($courses)->contains('id', $this->course))
        ->where('limits.stemMax', 3000)
        // The four levels KMU uses; Evaluation and Synthesis are rows that are switched off.
        ->has('cognitiveLevels', 4)
        ->has('difficultyLevels', 3));

    $this->actingAs($author)->getJson('/questions/curriculum?course_id='.$this->course)
        ->assertOk()
        ->assertJsonPath('nodes.0.id', $this->node)
        ->assertJsonPath('nodes.0.allows_questions', true);

    $cityProgramme = $this->cmsProgramme($this->cmsBranch('City Campus'), 'DPT');
    $otherBranchCourse = $this->cmsCourse($cityProgramme, $this->cmsProfessional($cityProgramme), 'PHY');
    $this->actingAs($author)->getJson('/questions/curriculum?course_id='.$otherBranchCourse)->assertForbidden();
});

test('the question list shows the newest version of each question, with filters', function () {
    $author = ($this->author)();
    $this->actingAs($author)->post('/questions', sba(['stem' => '<p>A child with a barking cough and stridor at night.</p>']));
    $this->actingAs($author)->post('/questions', sba());
    $mine = QuestionVersion::query()->orderBy('id')->first();
    $this->actingAs($author)->post("/questions/{$mine->question_id}/versions/{$mine->id}/submit");

    $this->actingAs($author)->get('/questions')->assertInertia(fn ($page) => $page
        ->component('qbank/Questions')
        ->where('questions.total', 2)
        // KMU's statuses, in its order: "Submitted for Review" covers submitted and under review.
        ->where('statuses', fn ($statuses) => collect($statuses)->pluck('count', 'label')->all() === [
            'Draft' => 1, 'Submitted for Review' => 1, 'Review' => 0, 'Revise' => 0,
            'Accept' => 0, 'Retain in QBank' => 0, 'Remove / Discard' => 0,
        ])
        ->where('questions.data.0.reference', fn ($ref) => str_starts_with((string) $ref, 'Q-')));

    $this->actingAs($author)->get('/questions?status=submitted')->assertInertia(fn ($page) => $page->where('questions.total', 1));
    $this->actingAs($author)->get('/questions?search=barking cough')->assertInertia(fn ($page) => $page->where('questions.total', 1));
    $this->actingAs($author)->get('/questions?mine=1')->assertInertia(fn ($page) => $page->where('questions.total', 2));

    $colleague = ($this->author)();
    $this->actingAs($colleague)->get('/questions?mine=1')->assertInertia(fn ($page) => $page->where('questions.total', 0));
});

test('question references are handed out one at a time', function () {
    $author = ($this->author)();

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($author)->post('/questions', sba());
    }

    $refs = Question::query()->orderBy('id')->pluck('public_ref')->all();
    $year = now()->year;

    expect($refs)->toBe(["Q-{$year}-000001", "Q-{$year}-000002", "Q-{$year}-000003"]);
});

/**
 * What the screens offer is what the CMS role allows, and nothing more: the menu, the buttons on
 * the question list, and the routes behind them all read the same permissions.
 */
test('the screens offer only what the CMS role permits', function () {
    $reader = ($this->author)(['qbank_questions' => ['view']]);

    $this->actingAs($reader)->get('/questions')->assertOk()->assertInertia(fn ($page) => $page
        ->where('auth.can.viewQuestions', true)
        ->where('auth.can.createQuestions', false)
        ->where('auth.can.importQuestions', false)
        ->where('auth.can.reviewQuestions', false)
        ->where('auth.can.approveQuestions', false)
        ->where('canEditOwn', false)
        ->where('canEditAny', false));

    // Every route behind a button it does not offer refuses them as well.
    $this->actingAs($reader)->get('/questions/create')->assertForbidden();
    $this->actingAs($reader)->get('/questions/imports')->assertForbidden();
    $this->actingAs($reader)->get('/reviews')->assertForbidden();
    $this->actingAs($reader)->get('/approvals')->assertForbidden();

    // An author of their own questions may edit their own, not anybody else's.
    $author = ($this->author)();
    $this->actingAs($author)->get('/questions')->assertInertia(fn ($page) => $page
        ->where('auth.can.createQuestions', true)
        ->where('canEditOwn', true)
        ->where('canEditAny', false));
});
