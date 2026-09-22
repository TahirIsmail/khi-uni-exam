<?php

use App\Domain\Blueprint\BlueprintFingerprint;
use App\Domain\Blueprint\Enums\BlueprintStatus;
use App\Domain\Blueprint\Models\Blueprint;
use App\Domain\Exam\Models\Examination;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsExaminations;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class, BuildsExaminations::class);

beforeEach(function () {
    $this->examWorld();
    $this->exam = $this->newExam();
    $this->url = "/exams/{$this->exam->id}/blueprint";
});

function saveBlueprint(array $overrides = [])
{
    return test()->actingAs(test()->setter)->put(test()->url, test()->blueprintPayload($overrides));
}

function blueprintOf(Examination $exam): Blueprint
{
    return Blueprint::query()->where('examination_id', $exam->id)->firstOrFail();
}

/** A blueprint that adds up, saved and submitted. */
function submittedBlueprint(array $overrides = []): void
{
    saveBlueprint($overrides)->assertSessionHasNoErrors();
    test()->actingAs(test()->setter)->post(test()->url.'/submit')->assertSessionHasNoErrors();
}

test('an examiner writes a blueprint: sections, rows and the two mixes', function () {
    saveBlueprint([
        'sections' => ['Section A — Single best answer', 'Section B — Short answer'],
        'rows' => [
            ['section' => 0, 'node_id' => $this->node, 'question_type_id' => $this->typeId(), 'question_count' => 60, 'marks_each' => 1],
            ['section' => 1, 'node_id' => $this->otherNode, 'question_type_id' => $this->typeId('short_answer'), 'question_count' => 8, 'marks_each' => 5],
        ],
        'cognitive' => [['level_id' => 1, 'percent' => 40], ['level_id' => 2, 'percent' => 60]],
        'difficulty' => [['level_id' => 1, 'percent' => 30], ['level_id' => 2, 'percent' => 50], ['level_id' => 3, 'percent' => 20]],
    ])->assertRedirect($this->url)->assertSessionHasNoErrors();

    $blueprint = blueprintOf($this->exam);
    $rows = $blueprint->rows()->get();

    expect($blueprint->status)->toBe(BlueprintStatus::Draft)
        ->and($rows)->toHaveCount(2)
        ->and($rows[0]->question_count)->toBe(60)
        ->and($rows[1]->marks())->toBe(40.0)
        ->and($rows[0]->section_id)->not->toBeNull()
        ->and($rows[0]->section_id)->not->toBe($rows[1]->section_id)
        // The first topic names Physiology; the discipline is kept on the row.
        ->and($rows[0]->discipline_id)->toBe($this->discipline)
        ->and($blueprint->targets()->where('dimension', 'cognitive')->count())->toBe(2)
        ->and($blueprint->targets()->where('dimension', 'difficulty')->count())->toBe(3)
        ->and(DB::table('exm_sections')->where('examination_id', $this->exam->id)->orderBy('sort_order')->pluck('name')->all())
        ->toBe(['Section A — Single best answer', 'Section B — Short answer']);

    $audit = DB::table('sec_audit_logs')->where('action', 'blueprint.saved')->where('entity_id', (string) $blueprint->id)->first();
    expect(json_decode((string) $audit->new_values, true))->toMatchArray(['rows' => 2, 'questions' => 68, 'marks' => 100.0, 'sections' => 2, 'targets' => 5]);
});

test('saving again replaces what was there, all or nothing', function () {
    saveBlueprint()->assertSessionHasNoErrors();
    saveBlueprint(['rows' => [['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId(), 'question_count' => 100, 'marks_each' => 1]]])
        ->assertSessionHasNoErrors();

    expect(blueprintOf($this->exam)->rows()->count())->toBe(1);

    // A refused save leaves the last good one untouched.
    saveBlueprint(['rows' => [['section' => null, 'node_id' => 999999, 'question_type_id' => $this->typeId(), 'question_count' => 5, 'marks_each' => 1]]])
        ->assertSessionHasErrors('rows.0.node_id');
    expect(blueprintOf($this->exam)->rows()->count())->toBe(1)
        ->and(blueprintOf($this->exam)->rows()->first()->question_count)->toBe(100);
});

test('a draft may be incomplete, but what it says has to be true', function () {
    // Rows that do not add up to the total marks are fine in a draft.
    saveBlueprint(['rows' => [['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId(), 'question_count' => 3, 'marks_each' => 1]]])
        ->assertSessionHasNoErrors();

    $row = ['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId(), 'question_count' => 5, 'marks_each' => 1];
    $bad = fn (array $rows, string $key) => saveBlueprint(['rows' => $rows])->assertSessionHasErrors($key);

    $bad([array_replace($row, ['node_id' => 0])], 'rows.0.node_id');
    $bad([array_replace($row, ['question_type_id' => 250])], 'rows.0.question_type_id');
    $bad([array_replace($row, ['question_count' => 0])], 'rows.0.question_count');
    $bad([array_replace($row, ['question_count' => 5000])], 'rows.0.question_count');
    $bad([array_replace($row, ['marks_each' => 0])], 'rows.0.marks_each');
    $bad([array_replace($row, ['section' => 3])], 'rows.0.section');
    $bad([$row, $row], 'rows.1.node_id');
});

test('a topic of another course cannot be used', function () {
    $other = $this->cmsCourse($this->programme, $this->professional, 'RESP');
    $foreign = $this->cmsCurriculumNode($other, $this->programme, 'Asthma');

    saveBlueprint(['rows' => [['section' => null, 'node_id' => $foreign, 'question_type_id' => $this->typeId(), 'question_count' => 5, 'marks_each' => 1]]])
        ->assertSessionHasErrors('rows.0.node_id');
});

test('a heading can be a row: everything under it counts', function () {
    $heading = $this->cmsCurriculumNode($this->course, $this->programme, 'Cardiology', allowQuestions: false);
    $child = $this->cmsCurriculumNode($this->course, $this->programme, 'Arrhythmias', parentId: $heading);

    saveBlueprint(['rows' => [['section' => null, 'node_id' => $heading, 'question_type_id' => $this->typeId(), 'question_count' => 5, 'marks_each' => 1]]])
        ->assertSessionHasNoErrors();

    // Two questions in the topic under it count for the heading too.
    $this->activeQuestion($child);
    $this->activeQuestion($child);

    $this->actingAs($this->setter)->get($this->url)->assertInertia(fn ($page) => $page
        ->where("availability.{$heading}.{$this->typeId()}", 2)
        ->where("availability.{$child}.{$this->typeId()}", 2));
});

test('the mixes have to add up to 100%, and each level is used once', function () {
    saveBlueprint(['cognitive' => [['level_id' => 1, 'percent' => 40], ['level_id' => 2, 'percent' => 40]]])->assertSessionHasErrors('cognitive');
    saveBlueprint(['difficulty' => [['level_id' => 1, 'percent' => 100], ['level_id' => 1, 'percent' => 0]]])->assertSessionHasErrors('difficulty.1.level_id');
    saveBlueprint(['cognitive' => [['level_id' => 99, 'percent' => 100]]])->assertSessionHasErrors('cognitive.0.level_id');

    saveBlueprint(['cognitive' => [['level_id' => 1, 'percent' => 33.33], ['level_id' => 2, 'percent' => 33.33], ['level_id' => 3, 'percent' => 33.34]]])
        ->assertSessionHasNoErrors();
});

test('the screen is given the totals, the findings and what the bank holds', function () {
    $this->activeQuestion($this->node);
    $this->activeQuestion($this->node);
    // Not counted: archived, another examination type, another type of question, a draft.
    $this->activeQuestion($this->node, archived: true);
    $this->activeQuestion($this->node, examTypeId: $this->cmsExamType('supplementary'));
    $this->activeQuestion($this->node, typeId: $this->typeId('short_answer'));
    QuestionVersion::factory()->create([
        'question_id' => Question::factory()->create(['branch_id' => $this->branch, 'course_id' => $this->course])->id,
        'course_id' => $this->course, 'node_id' => $this->node, 'exam_type_id' => $this->annual,
    ]);

    saveBlueprint()->assertSessionHasNoErrors();

    $this->actingAs($this->setter)->get($this->url)->assertOk()->assertInertia(fn ($page) => $page
        ->component('exams/Blueprint')
        ->where('report.plannedQuestions', 80)
        ->where('report.plannedMarks', 100)
        ->where('report.isBalanced', true)
        ->where('report.blockers', [])
        ->where('availability', fn ($matrix) => ($matrix[$this->node][$this->typeId()] ?? 0) === 2
            && ($matrix[$this->node][$this->typeId('short_answer')] ?? 0) === 1)
        // 60 wanted from the first topic, 2 in the bank; 20 wanted from the other, none.
        ->where('report.warnings', fn ($warnings) => count($warnings) === 2 && str_contains($warnings[0], '60 wanted, 2 in the question bank'))
        ->where('forEditing', true)
        ->where('topics.0.label', fn ($label) => is_string($label)));
});

test('a blueprint whose rows do not add up cannot be submitted', function () {
    saveBlueprint(['rows' => [['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId(), 'question_count' => 30, 'marks_each' => 1]]])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->setter)->from($this->url)->post($this->url.'/submit')->assertSessionHasErrors('blueprint');
    expect(blueprintOf($this->exam)->status)->toBe(BlueprintStatus::Draft);

    $this->actingAs($this->setter)->get("/exams/{$this->exam->id}")->assertInertia(fn ($page) => $page
        ->where('report.difference', -70)
        ->where('report.blockers.0', fn ($text) => str_contains($text, '30 marks') && str_contains($text, 'add 70')));
});

test('an empty blueprint cannot be submitted, nor one whose mix is wrong', function () {
    $this->actingAs($this->setter)->from($this->url)->post($this->url.'/submit')->assertSessionHasErrors('blueprint');

    saveBlueprint()->assertSessionHasNoErrors();
    // A mix that stopped adding up (the database does not check it: the workflow does).
    DB::table('exm_blueprint_targets')->insert(['blueprint_id' => blueprintOf($this->exam)->id, 'dimension' => 'cognitive', 'level_id' => 1, 'percent' => 60, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->setter)->from($this->url)->post($this->url.'/submit')->assertSessionHasErrors('blueprint');
});

test('the blueprint goes from draft to submitted to approved, by two different people', function () {
    submittedBlueprint();
    $blueprint = blueprintOf($this->exam);

    expect($blueprint->status)->toBe(BlueprintStatus::Submitted)
        ->and($blueprint->submitted_by)->toBe($this->setter->id)
        ->and($blueprint->submitted_at)->not->toBeNull();

    // The person who wrote it is not the one who approves it — even holding the right.
    $this->cmsGrant($this->setterRole, 'exam_blueprints_approve', 'view');
    app(AccessControl::class)->forget($this->setter);
    $this->actingAs($this->setter)->post($this->url.'/approve')->assertForbidden();
    $this->actingAs($this->setter)->get("/exams/{$this->exam->id}")->assertInertia(fn ($page) => $page->where('can.approve', false)->where('can.sendBack', true));

    $this->actingAs($this->approver)->post($this->url.'/approve')->assertRedirect("/exams/{$this->exam->id}");

    $blueprint->refresh();
    expect($blueprint->status)->toBe(BlueprintStatus::Approved)
        ->and($blueprint->approved_by)->toBe($this->approver->id)
        ->and($blueprint->approved_hash)->toHaveLength(64)
        ->and($this->exam->fresh()->status->value)->toBe('blueprint_approved');

    foreach (['blueprint.saved', 'blueprint.submitted', 'blueprint.approved'] as $action) {
        expect(DB::table('sec_audit_logs')->where('action', $action)->where('entity_id', (string) $blueprint->id)->exists())->toBeTrue();
    }
});

test('the approver sends a blueprint back with a reason, and the author sees it', function () {
    submittedBlueprint();

    $this->actingAs($this->approver)->from("/exams/{$this->exam->id}")->post($this->url.'/send-back', ['reason' => 'short'])->assertSessionHasErrors('reason');
    $this->actingAs($this->approver)->post($this->url.'/send-back', ['reason' => 'Too many recall questions; shift 10 marks to application.'])->assertRedirect();

    $blueprint = blueprintOf($this->exam);
    expect($blueprint->status)->toBe(BlueprintStatus::Draft)
        ->and($blueprint->return_reason)->toContain('shift 10 marks')
        ->and($blueprint->submitted_by)->toBeNull();

    $this->actingAs($this->setter)->get($this->url)->assertInertia(fn ($page) => $page
        ->where('blueprint.returnReason', fn ($reason) => str_contains($reason, 'shift 10 marks'))
        ->where('forEditing', true));

    // Submitted again, the reason is cleared.
    $this->actingAs($this->setter)->post($this->url.'/submit')->assertSessionHasNoErrors();
    expect(blueprintOf($this->exam)->return_reason)->toBeNull();
});

test('an approved blueprint is reopened with a reason, and the approval is forgotten', function () {
    submittedBlueprint();
    $this->actingAs($this->approver)->post($this->url.'/approve')->assertSessionHasNoErrors();

    $this->actingAs($this->approver)->from("/exams/{$this->exam->id}")->post($this->url.'/reopen', ['reason' => 'x'])->assertSessionHasErrors('reason');
    $this->actingAs($this->approver)->post($this->url.'/reopen', ['reason' => 'The paper is to be split into two sections.'])->assertRedirect();

    $blueprint = blueprintOf($this->exam);
    expect($blueprint->status)->toBe(BlueprintStatus::Draft)
        ->and($blueprint->approved_by)->toBeNull()
        ->and($blueprint->approved_hash)->toBeNull()
        ->and($this->exam->fresh()->status->value)->toBe('draft');

    $reopened = DB::table('sec_audit_logs')->where('action', 'blueprint.reopened')->first();
    expect($reopened->reason)->toContain('two sections')
        ->and(json_decode((string) $reopened->old_values, true))->toHaveKey('fingerprint');
});

test('only the right person can take each step', function () {
    saveBlueprint()->assertSessionHasNoErrors();

    // The committee member does not write or submit; the officer does not approve.
    $this->actingAs($this->approver)->put($this->url, $this->blueprintPayload())->assertForbidden();
    $this->actingAs($this->approver)->post($this->url.'/submit')->assertForbidden();
    $this->actingAs($this->setter)->post($this->url.'/approve')->assertForbidden();
    $this->actingAs($this->setter)->post($this->url.'/send-back', ['reason' => 'Not allowed to do this.'])->assertForbidden();
    $this->actingAs($this->setter)->post($this->url.'/reopen', ['reason' => 'Not allowed to do this.'])->assertForbidden();

    // Approving needs the blueprint to be submitted.
    $this->actingAs($this->approver)->from("/exams/{$this->exam->id}")->post($this->url.'/approve')->assertSessionHasErrors('blueprint');
});

test('a submitted blueprint cannot be edited through the screen', function () {
    submittedBlueprint();

    saveBlueprint(['rows' => [['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId(), 'question_count' => 100, 'marks_each' => 1]]])
        ->assertSessionHasErrors('blueprint');
    expect(blueprintOf($this->exam)->rows()->count())->toBe(2);

    $this->actingAs($this->setter)->get($this->url)->assertInertia(fn ($page) => $page->where('forEditing', false)->where('can.editBlueprint', false));
});

test('a submitted or approved blueprint is frozen in the database too', function () {
    submittedBlueprint();
    $blueprint = blueprintOf($this->exam);
    $row = $blueprint->rows()->first();

    foreach ([
        fn () => DB::table('exm_blueprint_rows')->where('id', $row->id)->update(['question_count' => 1]),
        fn () => DB::table('exm_blueprint_rows')->where('id', $row->id)->delete(),
        fn () => DB::table('exm_blueprint_rows')->insert(['blueprint_id' => $blueprint->id, 'node_id' => $this->node, 'question_type_id' => $this->typeId(), 'question_count' => 1, 'marks_each' => 1, 'created_at' => now(), 'updated_at' => now()]),
        fn () => DB::table('exm_blueprint_targets')->insert(['blueprint_id' => $blueprint->id, 'dimension' => 'cognitive', 'level_id' => 1, 'percent' => 100, 'created_at' => now(), 'updated_at' => now()]),
        fn () => DB::table('exm_sections')->insert(['examination_id' => $this->exam->id, 'name' => 'Late section', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]),
    ] as $attempt) {
        expect($attempt)->toThrow(QueryException::class, 'submitted or approved');
    }

    $this->actingAs($this->approver)->post($this->url.'/approve')->assertSessionHasNoErrors();
    expect(fn () => DB::table('exm_blueprint_rows')->where('id', $row->id)->update(['marks_each' => 3]))->toThrow(QueryException::class, 'submitted or approved');
});

test('the database allows only the steps of the workflow', function () {
    saveBlueprint()->assertSessionHasNoErrors();
    $blueprint = blueprintOf($this->exam);
    $set = fn (string $status) => fn () => DB::table('exm_blueprints')->where('id', $blueprint->id)->update(['status' => $status]);

    // Approval cannot skip the submission.
    expect($set('approved'))->toThrow(QueryException::class, 'not an allowed status change');

    DB::table('exm_blueprints')->where('id', $blueprint->id)->update(['status' => 'submitted']);
    DB::table('exm_blueprints')->where('id', $blueprint->id)->update(['status' => 'approved']);
    expect($set('submitted'))->toThrow(QueryException::class, 'not an allowed status change')
        ->and(fn () => DB::table('exm_blueprints')->where('id', $blueprint->id)->update(['examination_id' => 999]))->toThrow(QueryException::class);
});

test('the fingerprint is stable, and changes when the blueprint does', function () {
    submittedBlueprint();
    $this->actingAs($this->approver)->post($this->url.'/approve')->assertSessionHasNoErrors();

    $blueprint = blueprintOf($this->exam);
    $first = (string) $blueprint->approved_hash;

    // Worked out again from what is stored, it is the same.
    expect(app(BlueprintFingerprint::class)->of($this->exam, $blueprint))->toBe($first);

    // Reopened, changed and approved again, it is another.
    $this->actingAs($this->approver)->post($this->url.'/reopen', ['reason' => 'Rebalance the two topics of the paper.'])->assertSessionHasNoErrors();
    saveBlueprint(['rows' => [
        ['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId(), 'question_count' => 50, 'marks_each' => 2],
    ]])->assertSessionHasNoErrors();
    $this->actingAs($this->setter)->post($this->url.'/submit')->assertSessionHasNoErrors();
    $this->actingAs($this->approver)->post($this->url.'/approve')->assertSessionHasNoErrors();

    expect(blueprintOf($this->exam)->approved_hash)->not->toBe($first);
});

test('the blueprint of a course outside a person\'s exam access is out of reach', function () {
    $limited = $this->staffUser([$this->setterRole], $this->branch);
    $this->cmsExamScope($limited, 'programme', $this->cmsProgramme($this->branch, 'BDS'));

    $this->actingAs($limited)->get($this->url)->assertForbidden();
    $this->actingAs($limited)->put($this->url, $this->blueprintPayload())->assertForbidden();
    $this->actingAs($limited)->post($this->url.'/submit')->assertForbidden();
});

test('a blueprint is written and read only in its own campus', function () {
    $elsewhere = $this->staffUser([$this->setterRole], $this->cmsBranch('City Campus'));
    app(AccessControl::class)->forget($elsewhere);

    $this->actingAs($elsewhere)->get($this->url)->assertNotFound();
    $this->actingAs($elsewhere)->put($this->url, $this->blueprintPayload())->assertNotFound();
});

test('a reader sees the blueprint but cannot change it', function () {
    saveBlueprint()->assertSessionHasNoErrors();

    $this->actingAs($this->approver)->get($this->url)->assertOk()->assertInertia(fn ($page) => $page
        ->where('forEditing', false)
        ->where('can.editBlueprint', false)
        ->where('can.submit', false)
        ->where('blueprint.rows', fn ($rows) => count($rows) === 2));
});

test('a blueprint asking for more than the question bank holds cannot be submitted', function () {
    config(['exam.blueprint.require_questions_in_bank' => true]);
    saveBlueprint()->assertSessionHasNoErrors();

    // 60 and 20 wanted; the bank is empty.
    $this->actingAs($this->setter)->from($this->url)->post($this->url.'/submit')->assertSessionHasErrors('blueprint');
    expect(blueprintOf($this->exam)->status)->toBe(BlueprintStatus::Draft);

    $this->actingAs($this->setter)->get($this->url)->assertInertia(fn ($page) => $page
        ->where('limits.requireBank', true)
        ->where('report.blockers', fn ($blockers) => collect($blockers)->contains(fn ($text) => str_contains($text, '60 wanted, 0 in the question bank — 60 more to write or import'))
            && collect($blockers)->contains(fn ($text) => str_contains($text, '20 wanted, 0 in the question bank')))
        ->where('report.warnings', []));

    // Enough for the second topic, still short for the first: it names what is missing.
    foreach (range(1, 20) as $i) {
        $this->activeQuestion($this->otherNode);
    }
    foreach (range(1, 59) as $i) {
        $this->activeQuestion($this->node);
    }
    $this->actingAs($this->setter)->from($this->url)->post($this->url.'/submit')->assertSessionHasErrors('blueprint');
    $this->actingAs($this->setter)->get($this->url)->assertInertia(fn ($page) => $page
        ->where('report.blockers', fn ($blockers) => count($blockers) === 1 && str_contains($blockers[0], '60 wanted, 59 in the question bank — 1 more')));

    $this->activeQuestion($this->node);
    $this->actingAs($this->setter)->post($this->url.'/submit')->assertSessionHasNoErrors();
    expect(blueprintOf($this->exam)->status)->toBe(BlueprintStatus::Submitted);
});

test('a heading is asked for what its own rows and the rows below it ask', function () {
    config(['exam.blueprint.require_questions_in_bank' => true]);
    $heading = $this->cmsCurriculumNode($this->course, $this->programme, 'Cardiology', allowQuestions: false);
    $child = $this->cmsCurriculumNode($this->course, $this->programme, 'Arrhythmias', parentId: $heading);
    $exam = $this->newExam(['total_marks' => 8]);
    $url = "/exams/{$exam->id}/blueprint";

    // Five from anywhere under the heading and three from one topic under it: eight come from the same six.
    foreach (range(1, 6) as $i) {
        $this->activeQuestion($child);
    }
    $this->actingAs($this->setter)->put($url, $this->blueprintPayload(['rows' => [
        ['section' => null, 'node_id' => $heading, 'question_type_id' => $this->typeId(), 'question_count' => 5, 'marks_each' => 1],
        ['section' => null, 'node_id' => $child, 'question_type_id' => $this->typeId(), 'question_count' => 3, 'marks_each' => 1],
    ]]))->assertSessionHasNoErrors();

    $this->actingAs($this->setter)->from($url)->post($url.'/submit')->assertSessionHasErrors('blueprint');
    $this->actingAs($this->setter)->get($url)->assertInertia(fn ($page) => $page
        ->where('report.blockers', fn ($blockers) => collect($blockers)->contains(fn ($text) => str_contains($text, 'Cardiology, ') && str_contains($text, '8 wanted, 6 in the question bank'))));

    $this->activeQuestion($child);
    $this->activeQuestion($child);
    $this->actingAs($this->setter)->post($url.'/submit')->assertSessionHasNoErrors();
});

test('the bank is checked again when the blueprint is approved', function () {
    config(['exam.blueprint.require_questions_in_bank' => true]);
    $versions = [];
    foreach (range(1, 60) as $i) {
        $versions[] = $this->activeQuestion($this->node);
    }
    foreach (range(1, 20) as $i) {
        $this->activeQuestion($this->otherNode);
    }
    submittedBlueprint();

    // A question leaves the bank while the blueprint waits.
    DB::table('qb_questions')->where('id', $versions[0]->question_id)->update(['is_archived' => true]);
    $this->actingAs($this->approver)->from("/exams/{$this->exam->id}")->post($this->url.'/approve')->assertSessionHasErrors('blueprint');
    expect(blueprintOf($this->exam)->status)->toBe(BlueprintStatus::Submitted);

    DB::table('qb_questions')->where('id', $versions[0]->question_id)->update(['is_archived' => false]);
    $this->actingAs($this->approver)->post($this->url.'/approve')->assertSessionHasNoErrors();
    expect(blueprintOf($this->exam)->status)->toBe(BlueprintStatus::Approved);
});

test('where the institution allows it, a short bank is a warning and the blueprint goes through', function () {
    config(['exam.blueprint.require_questions_in_bank' => false]);
    submittedBlueprint();
    expect(blueprintOf($this->exam)->status)->toBe(BlueprintStatus::Submitted);

    $this->actingAs($this->approver)->get("/exams/{$this->exam->id}")->assertInertia(fn ($page) => $page
        ->where('report.blockers', [])
        ->where('report.warnings', fn ($warnings) => count($warnings) === 2));
});

test('the one waiting is told whom to ask, and the approvers find it under their menu', function () {
    submittedBlueprint();
    $url = "/exams/{$this->exam->id}";
    $named = fn (string $name) => ['name' => $name, 'surname' => 'Approver'];

    // Two who can approve, and three who are not named: another campus, a deactivated account, no right.
    $second = $this->staffUser([$this->approverRole], $this->branch, $named('Second'));
    $superAdmin = $this->staffUser([$this->cmsRole('Head of examinations', superAdmin: true)], $this->branch, $named('Head'));
    $far = $this->staffUser([$this->approverRole], $this->cmsBranch('City Campus'), $named('Faraway'));
    $gone = $this->staffUser([$this->approverRole], $this->branch, $named('Gone'));
    DB::table(config('database.cms_source_database').'.staff')->where('id', $gone->cms_staff_id)->update(['is_active' => 0]);
    $plain = $this->staffUser([$this->cmsRole('Cleaner')], $this->branch, $named('Plain'));

    $props = $this->actingAs($this->setter)->get($url)->assertOk()->viewData('page')['props'];
    $listed = collect($props['approvers']);

    expect($listed->contains('Second Approver'))->toBeTrue()
        ->and($listed->contains('Head Approver'))->toBeTrue()
        ->and($listed->contains('Faraway Approver'))->toBeFalse()
        ->and($listed->contains('Gone Approver'))->toBeFalse()
        ->and($listed->contains('Plain Approver'))->toBeFalse()
        // Never the person who wrote and submitted it.
        ->and($props['can']['approve'])->toBeFalse();

    // Under their menu: a count of what waits, not counting what they wrote themselves.
    $this->actingAs($this->approver)->get('/exams')->assertInertia(fn ($page) => $page
        ->where('auth.can.approveBlueprints', true)
        ->where('auth.awaiting.blueprints', 1)
        ->where('waitingForMe', 1));
    $this->actingAs($this->setter)->get('/exams')->assertInertia(fn ($page) => $page
        ->where('auth.can.approveBlueprints', false)
        ->where('auth.awaiting.blueprints', 0)
        ->where('waitingForMe', 0));
    unset($far, $plain);
});

test('a Super Admin who wrote a blueprint is not offered its approval either', function () {
    $admin = $this->staffUser([$this->cmsRole('Head of examinations', superAdmin: true)], $this->branch, ['name' => 'Head', 'surname' => 'Approver']);
    $exam = $this->newExam();
    $url = "/exams/{$exam->id}/blueprint";
    $this->actingAs($admin)->put($url, $this->blueprintPayload())->assertSessionHasNoErrors();
    $this->actingAs($admin)->post($url.'/submit')->assertSessionHasNoErrors();

    $this->actingAs($admin)->from($url)->post($url.'/approve')->assertForbidden();
    $this->actingAs($admin)->get("/exams/{$exam->id}")->assertInertia(fn ($page) => $page
        ->where('can.approve', false)
        ->where('can.sendBack', true)
        // The committee member is the only other person who can.
        ->where('approvers', fn ($names) => count($names) === 1));

    // With nobody else holding the right, the list is empty and the screen says whom to ask for.
    DB::table(config('database.cms_source_database').'.staff')->where('id', $this->approver->cms_staff_id)->update(['is_active' => 0]);
    $this->actingAs($admin)->get("/exams/{$exam->id}")->assertInertia(fn ($page) => $page->where('approvers', []));
});
