<?php

use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\VersionStatusLog;
use App\Domain\QuestionBank\Support\TextDiff;
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
    $this->discipline = $this->cmsDiscipline('Physiology');
    $this->node = $this->cmsCurriculumNode($this->course, $this->programme, 'Acute coronary syndrome', disciplineId: $this->discipline);

    $role = $this->cmsRole('Faculty');
    $this->cmsGrant($role, 'qbank_questions', 'view', 'add', 'edit');
    $this->author = $this->staffUser([$role], $this->branch);
});

function payload(array $overrides = []): array
{
    return array_replace([
        'question_type_id' => (int) QuestionType::query()->where('code', 'single_best_answer')->value('id'),
        'course_id' => test()->course,
        'node_id' => test()->node,
        'stem' => '<p>A 54-year-old man has crushing chest pain radiating to the jaw.</p>',
        'lead_in' => 'Which investigation is most useful first?',
        'marks' => 1,
        'negative_marks' => 0,
        'exam_type_id' => test()->cmsExamType('annual'),
        'cognitive_level_id' => 2,
        'difficulty_level_id' => 2,
        'options' => [
            ['label' => 'A', 'body' => 'ECG', 'is_correct' => true, 'sort_order' => 1],
            ['label' => 'B', 'body' => 'Chest radiograph', 'is_correct' => false, 'sort_order' => 2],
        ],
    ], $overrides);
}

function write(array $overrides = []): QuestionVersion
{
    test()->actingAs(test()->author)->post('/questions', payload($overrides))->assertRedirect();

    return QuestionVersion::query()->latest('id')->firstOrFail();
}

test('searching finds questions by their words and by their reference', function () {
    $chest = write();
    write(['stem' => '<p>A child with a barking cough and stridor at night.</p>']);

    $this->actingAs($this->author)->get('/questions?search=barking cough')
        ->assertInertia(fn ($page) => $page->where('questions.total', 1)
            ->where('questions.data.0.summary', fn ($summary) => str_contains((string) $summary, 'barking cough')));

    $this->actingAs($this->author)->get('/questions?search='.$chest->question->public_ref)
        ->assertInertia(fn ($page) => $page->where('questions.total', 1)
            ->where('questions.data.0.reference', $chest->question->public_ref));

    $this->actingAs($this->author)->get('/questions?search=nothing like this')
        ->assertInertia(fn ($page) => $page->where('questions.total', 0));
});

test('the filters narrow the search, one by one and together', function () {
    $mcq = write();
    $essayType = (int) QuestionType::query()->where('code', 'essay')->value('id');
    $secondTopic = $this->cmsCurriculumNode($this->course, $this->programme, 'Heart failure');
    $essay = write([
        'question_type_id' => $essayType,
        'node_id' => $secondTopic,
        'options' => [],
        'marks' => 10,
        'cognitive_level_id' => 5,
        'difficulty_level_id' => 3,
    ]);

    $expect = fn (string $query, int $total) => $this->actingAs($this->author)->get('/questions?'.$query)
        ->assertInertia(fn ($page) => $page->where('questions.total', $total));

    $expect('type_id='.$essayType, 1);
    $expect('node_id='.$secondTopic, 1);
    $expect('discipline_id='.$this->discipline, 1);
    $expect('marks_min=5', 1);
    $expect('marks_max=5', 1);
    $expect('cognitive_level_id=5', 1);
    $expect('difficulty_level_id=3', 1);
    $expect('programme_id='.$this->programme, 2);
    $expect('course_id='.$this->course, 2);
    $expect('author_id='.$this->author->id, 2);
    $expect('type_id='.$essayType.'&marks_min=5', 1);
    $expect('type_id='.$essayType.'&marks_max=5', 0);
    $expect('updated_from='.now()->toDateString(), 2);
    $expect('updated_to='.now()->subDay()->toDateString(), 0);

    expect($mcq->marks)->toBe(1.0)->and($essay->marks)->toBe(10.0);
});

test('a topic search includes the topics under it', function () {
    $parentId = $this->node;
    $cms = config('database.cms_source_database');
    $child = $this->cmsCurriculumNode($this->course, $this->programme, 'ECG changes');
    DB::table("{$cms}.acad_curriculum_nodes")->where('id', $child)->update([
        'parent_id' => $parentId,
        'path' => '/'.$parentId.'/',
        'depth' => 3,
    ]);
    // The programme's third level also takes questions (as MBBS has subtopics in the CMS).
    DB::table("{$cms}.acad_level_templates")->where(['class_id' => $this->programme, 'depth' => 3])->delete();
    DB::table("{$cms}.acad_level_templates")->insert([
        'class_id' => $this->programme, 'depth' => 3,
        'level_type_id' => DB::table("{$cms}.acad_level_types")->where('code', 'subtopic')->value('id')
            ?? DB::table("{$cms}.acad_level_types")->insertGetId(['code' => 'subtopic', 'name' => 'Subtopic']),
        'allow_questions' => 1,
    ]);

    write(['node_id' => $child, 'stem' => '<p>Which lead shows reciprocal change in an inferior infarct?</p>']);
    write();

    $this->actingAs($this->author)->get('/questions?node_id='.$parentId)
        ->assertInertia(fn ($page) => $page->where('questions.total', 2));
    $this->actingAs($this->author)->get('/questions?node_id='.$child)
        ->assertInertia(fn ($page) => $page->where('questions.total', 1));
});

test('tags, sorting and the author list work', function () {
    $tag = $this->actingAs($this->author)->postJson('/questions/tags', ['name' => 'ECG'])->json();
    write(['tag_ids' => [$tag['id']], 'marks' => 2]);
    write(['marks' => 8]);

    $this->actingAs($this->author)->get('/questions?tag_id='.$tag['id'])
        ->assertInertia(fn ($page) => $page->where('questions.total', 1)->where('questions.data.0.marks', 2));

    $this->actingAs($this->author)->get('/questions?sort=marks')
        ->assertInertia(fn ($page) => $page->where('questions.data.0.marks', 8));
    $this->actingAs($this->author)->get('/questions?sort=reference')
        ->assertInertia(fn ($page) => $page->where('questions.data.0.versionNo', 1));

    $this->actingAs($this->author)->get('/questions')
        ->assertInertia(fn ($page) => $page->where('authors', [['id' => $this->author->id, 'name' => $this->author->name]])
            ->where('programmes', fn ($programmes) => count($programmes) === 1)
            ->where('types', fn ($types) => count($types) === 12));
});

test('questions with the same text can be found, and archived ones stay out of the way', function () {
    write();
    write();
    write(['stem' => '<p>A child with a barking cough and stridor at night.</p>']);

    $this->actingAs($this->author)->get('/questions?duplicates=1')
        ->assertInertia(fn ($page) => $page->where('questions.total', 2));

    Question::query()->latest('id')->first()->update(['is_archived' => true, 'archived_at' => now()]);

    $this->actingAs($this->author)->get('/questions')
        ->assertInertia(fn ($page) => $page->where('questions.total', 2));
    $this->actingAs($this->author)->get('/questions?archived=1')
        ->assertInertia(fn ($page) => $page->where('questions.total', 1));
});

test('a made-up filter value is refused instead of being ignored', function () {
    $this->actingAs($this->author)->from('/questions')->get('/questions?status=whatever')->assertSessionHasErrors('status');
    $this->actingAs($this->author)->from('/questions')->get('/questions?type_id=9999')->assertSessionHasErrors('type_id');
    $this->actingAs($this->author)->from('/questions')->get('/questions?sort=drop+table')->assertSessionHasErrors('sort');
    $this->actingAs($this->author)->from('/questions')->get('/questions?updated_from=yesterday')->assertSessionHasErrors('updated_from');
});

test('the history of a question shows every version and step, newest first', function () {
    $first = write();
    $question = $first->question;
    $this->actingAs($this->author)->post("/questions/{$question->id}/versions/{$first->id}/submit", ['note' => 'Ready for review']);
    $first->refresh();

    // The review steps themselves arrive with the review screens; here they are recorded as they will be.
    foreach ([[VersionStatus::Submitted, VersionStatus::UnderReview, null], [VersionStatus::UnderReview, VersionStatus::ChangesRequested, 'Two options overlap']] as $index => [$from, $to, $note]) {
        $first->update(['status' => $to]);
        VersionStatusLog::query()->create([
            'version_id' => $first->id, 'from_status' => $from, 'to_status' => $to,
            'actor_id' => $this->author->id, 'reason' => $note, 'occurred_at' => now()->addSeconds($index + 1),
        ]);
    }
    $this->actingAs($this->author)->put("/questions/{$question->id}/versions/{$first->id}", payload(['stem' => '<p>Rewritten after review comments.</p>']));

    $this->actingAs($this->author)->get("/questions/{$question->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('qbank/QuestionHistory')
        ->where('question.reference', $question->public_ref)
        ->where('question.latestVersionNo', 1)
        ->has('versions', 1)
        ->where('versions.0.status', 'changes_requested')
        ->where('versions.0.author', $this->author->name)
        ->where('timeline', fn ($timeline) => collect($timeline)->pluck('what')->all() === [
            'Changes asked for', 'Given to a reviewer', 'Author proposed: Understanding · Moderate', 'Sent for review', 'Question written',
        ])
        ->where('timeline.0.note', 'Two options overlap')
        ->where('timeline.3.note', 'Ready for review'));
});

test('the history lists other questions with the same text', function () {
    $first = write();
    $second = write();

    $this->actingAs($this->author)->get("/questions/{$first->question_id}")->assertInertia(fn ($page) => $page
        ->where('duplicates', fn ($duplicates) => count($duplicates) === 1
            && $duplicates[0]['reference'] === $second->question->public_ref));
});

test('two versions are shown side by side, with the changed words, key and facts', function () {
    $first = write();
    $question = $first->question;
    $this->actingAs($this->author)->post("/questions/{$question->id}/versions/{$first->id}/submit");
    $this->actingAs($this->author)->post("/questions/{$question->id}/versions");
    $second = QuestionVersion::query()->where('question_id', $question->id)->where('version_no', 2)->firstOrFail();

    $this->actingAs($this->author)->put("/questions/{$question->id}/versions/{$second->id}", payload([
        'stem' => '<p>A 54-year-old woman has crushing chest pain radiating to the jaw.</p>',
        'marks' => 2,
        'options' => [
            ['label' => 'A', 'body' => 'ECG', 'is_correct' => false, 'sort_order' => 1],
            ['label' => 'B', 'body' => 'Coronary angiography', 'is_correct' => true, 'sort_order' => 2],
        ],
    ]));

    $this->actingAs($this->author)->get("/questions/{$question->id}/diff?from={$first->id}&to={$second->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('qbank/QuestionDiff')
            ->where('diff.from.versionNo', 1)
            ->where('diff.to.versionNo', 2)
            ->where('diff.text.stem.changed', true)
            ->where('diff.text.stem.parts', fn ($parts) => collect($parts)->contains(fn ($part) => $part['type'] === 'removed' && str_contains($part['text'], 'man'))
                && collect($parts)->contains(fn ($part) => $part['type'] === 'added' && str_contains($part['text'], 'woman')))
            ->where('diff.text.leadIn.changed', false)
            ->where('diff.options', fn ($options) => collect($options)->firstWhere('label', 'A')['keyChanged'] === true
                && collect($options)->firstWhere('label', 'B')['text']['changed'] === true)
            ->where('diff.facts', fn ($facts) => collect($facts)->firstWhere('label', 'Marks') === ['label' => 'Marks', 'before' => '1', 'after' => '2', 'changed' => true]));
});

test('a version of another question cannot be compared', function () {
    $mine = write();
    $other = write();

    $this->actingAs($this->author)->get("/questions/{$mine->question_id}/diff?from={$mine->id}&to={$other->id}")->assertNotFound();
    $this->actingAs($this->author)->get("/questions/{$mine->question_id}/diff?from={$mine->id}")->assertSessionHasErrors('to');
});

test('history and diff are refused for another campus and without the permission', function () {
    $version = write();
    $questionId = $version->question_id;

    DB::table('qb_questions')->where('id', $questionId)->update(['branch_id' => $this->cmsBranch('City Campus')]);
    $this->actingAs($this->author)->get("/questions/{$questionId}")->assertNotFound();

    DB::table('qb_questions')->where('id', $questionId)->update(['branch_id' => $this->branch]);
    $outsider = $this->staffUser([$this->cmsRole('Receptionist')], $this->branch);
    $this->actingAs($outsider)->get("/questions/{$questionId}")->assertForbidden();
});

test('the word diff shows the smallest set of changes', function () {
    $parts = TextDiff::words('the cat sat on the mat', 'the cat sat on the warm mat');

    expect(collect($parts)->pluck('type')->all())->toBe(['same', 'added', 'same'])
        ->and(collect($parts)->firstWhere('type', 'added')['text'])->toBe('warm')
        ->and(TextDiff::changed('same words', ' same words '))->toBeFalse()
        ->and(TextDiff::words('', 'new text'))->toBe([['type' => 'added', 'text' => 'new text']]);
});

test('the search narrows by year or semester, examination, and whether a question has been used', function () {
    $annual = write();
    $supplementary = write(['exam_type_id' => $this->cmsExamType('supplementary'), 'stem' => '<p>A supplementary examination question about the cardiac cycle.</p>']);

    $version = QuestionVersion::query()->findOrFail($annual->id);
    $year = (string) $version->professional_id;

    $this->actingAs($this->author)->get('/questions?year='.$year)->assertInertia(fn ($page) => $page->where('questions.total', 2));
    $this->actingAs($this->author)->get('/questions?year=999999')->assertInertia(fn ($page) => $page->where('questions.total', 0));

    $this->actingAs($this->author)->get('/questions?exam_type_id='.$this->cmsExamType('supplementary'))->assertInertia(fn ($page) => $page
        ->where('questions.total', 1)
        ->where('questions.data.0.id', $supplementary->question_id)
        ->where('questions.data.0.examType', 'Supplementary'));

    // Neither has been used in an examination yet.
    $this->actingAs($this->author)->get('/questions?used=unused')->assertInertia(fn ($page) => $page->where('questions.total', 2));
    $this->actingAs($this->author)->get('/questions?used=used')->assertInertia(fn ($page) => $page->where('questions.total', 0));

    // Once one has, the history of its use finds it by date.
    DB::table('qb_question_usage')->insert([
        'version_id' => $annual->id, 'question_id' => $annual->question_id, 'branch_id' => $this->branch,
        'exam_label' => 'MBBS First Professional Annual Examination 2026', 'used_on' => '2026-05-10',
        'candidates' => 251, 'observed_p' => 0.72, 'discrimination' => 0.31,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->author)->get('/questions?used=used')->assertInertia(fn ($page) => $page->where('questions.total', 1));
    $this->actingAs($this->author)->get('/questions?used_from=2026-05-01&used_to=2026-05-31')->assertInertia(fn ($page) => $page->where('questions.total', 1));
    $this->actingAs($this->author)->get('/questions?used_from=2026-06-01')->assertInertia(fn ($page) => $page->where('questions.total', 0));

    // And the question's page shows where it was used and how it performed.
    $this->actingAs($this->author)->get("/questions/{$annual->question_id}")->assertInertia(fn ($page) => $page
        ->where('usage.exams.0.exam', 'MBBS First Professional Annual Examination 2026')
        ->where('usage.exams.0.candidates', 251)
        ->where('usage.exams.0.difficultyIndex', 0.72));

    // Bad values are refused, not ignored.
    $this->actingAs($this->author)->get('/questions?year=abc')->assertSessionHasErrors('year');
    $this->actingAs($this->author)->get('/questions?used=sometimes')->assertSessionHasErrors('used');
});
