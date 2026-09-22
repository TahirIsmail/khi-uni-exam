<?php

use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Paper\Models\Paper;
use App\Domain\Paper\Models\PaperItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsExaminations;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class, BuildsExaminations::class);

beforeEach(function () {
    $this->examWorld();
});

/** An approved examination (60 + 20 questions from two topics) with its paper started. */
function paperExam(array $blueprint = [], array $examination = []): array
{
    $exam = test()->approvedExam($blueprint, $examination);
    test()->actingAs(test()->setter)->post("/exams/{$exam->id}/paper")->assertSessionHasNoErrors();

    return [$exam, Paper::query()->where('examination_id', $exam->id)->firstOrFail(), "/exams/{$exam->id}/paper"];
}

/** A small blueprint: three one-mark questions from the first topic, two two-mark ones from the second (7 marks). */
function smallBlueprint(array $overrides = []): array
{
    return array_replace([
        'rows' => [
            ['section' => null, 'node_id' => test()->node, 'question_type_id' => test()->typeId(), 'question_count' => 3, 'marks_each' => 1],
            ['section' => null, 'node_id' => test()->otherNode, 'question_type_id' => test()->typeId(), 'question_count' => 2, 'marks_each' => 2],
        ],
    ], $overrides);
}

/** The same, with the total marks the rows add up to. */
function smallExam(array $blueprint = []): array
{
    $blueprint = smallBlueprint($blueprint);
    $total = array_sum(array_map(fn (array $row): float => $row['question_count'] * $row['marks_each'], $blueprint['rows']));

    return paperExam($blueprint, ['total_marks' => $total]);
}

function fillPaper(string $url, string $mode = 'gaps')
{
    return test()->actingAs(test()->setter)->post($url.'/fill', ['mode' => $mode]);
}

function itemsOf(Paper $paper)
{
    return PaperItem::query()->where('paper_id', $paper->id)->orderBy('position')->get();
}

test('a paper is started only once the blueprint is approved', function () {
    $exam = $this->newExam(['total_marks' => 7]);
    $url = "/exams/{$exam->id}/paper";

    $this->actingAs($this->setter)->from($url)->post($url)->assertSessionHasErrors('paper');
    expect(Paper::query()->count())->toBe(0);

    $this->actingAs($this->setter)->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->component('exams/Paper')
        ->where('paper', null)
        ->where('blueprintApproved', false)
        ->where('mayStart', false));
});

test('the officer starts the paper once, and it is audited', function () {
    [$exam, $paper, $url] = smallExam();

    expect($paper->version_no)->toBe(1)
        ->and($paper->status->value)->toBe('draft')
        ->and($paper->shuffle_questions)->toBeTrue()
        ->and($paper->shuffle_options)->toBeTrue()
        ->and($paper->blueprint_hash)->toHaveLength(64)
        ->and(DB::table('sec_audit_logs')->where('action', 'paper.created')->where('entity_id', (string) $paper->id)->exists())->toBeTrue();

    $this->actingAs($this->setter)->from($url)->post($url)->assertSessionHasErrors('paper');
    expect(Paper::query()->count())->toBe(1);
});

test('the draw fills every row from the questions the bank can give it, and only those', function () {
    [$exam, $paper, $url] = smallExam();
    foreach (range(1, 5) as $i) {
        $this->activeQuestion($this->node);
        $this->activeQuestion($this->otherNode);
    }
    // Not eligible: another examination, another type, archived, another campus, a draft.
    $this->activeQuestion($this->node, examTypeId: $this->cmsExamType('supplementary'));
    $this->activeQuestion($this->node, typeId: $this->typeId('short_answer'));
    $this->activeQuestion($this->node, archived: true);
    $this->activeQuestion($this->node, branchId: $this->cmsBranch('City Campus'));

    fillPaper($url)->assertRedirect()->assertSessionHasNoErrors();

    $items = itemsOf($paper);
    expect($items)->toHaveCount(5)
        ->and($items->pluck('row_node_id')->all())->toBe([$this->node, $this->node, $this->node, $this->otherNode, $this->otherNode])
        ->and($items->pluck('marks')->all())->toBe([1.0, 1.0, 1.0, 2.0, 2.0])
        ->and($items->pluck('position')->all())->toBe([1, 2, 3, 4, 5])
        ->and($items->pluck('question_id')->unique())->toHaveCount(5)
        ->and($items->pluck('source')->unique()->all())->toBe(['auto']);

    // Each is a question the pool holds: this course and campus, in use, this examination's type.
    foreach ($items as $item) {
        $version = DB::table('qb_question_versions')->where('id', $item->version_id)->first();
        expect($version->status)->toBe('active')
            ->and((int) $version->exam_type_id)->toBe($this->annual)
            ->and((int) $version->branch_id)->toBe($this->branch)
            ->and((int) $version->question_type_id)->toBe($this->typeId());
    }

    $drawn = DB::table('sec_audit_logs')->where('action', 'paper.drawn')->first();
    expect(json_decode((string) $drawn->new_values, true))->toMatchArray(['mode' => 'gaps', 'added' => 5, 'missing' => 0]);
});

test('a heading row draws from everything below it', function () {
    $heading = $this->cmsCurriculumNode($this->course, $this->programme, 'Cardiology', allowQuestions: false);
    $child = $this->cmsCurriculumNode($this->course, $this->programme, 'Arrhythmias', parentId: $heading);
    [$exam, $paper, $url] = paperExam([
        'rows' => [['section' => null, 'node_id' => $heading, 'question_type_id' => $this->typeId(), 'question_count' => 2, 'marks_each' => 1]],
    ], ['total_marks' => 2]);

    $this->activeQuestion($child);
    $this->activeQuestion($child);
    // Outside the heading: not offered to it.
    $this->activeQuestion($this->node);

    fillPaper($url)->assertSessionHasNoErrors();

    expect(itemsOf($paper)->pluck('row_node_id')->unique()->all())->toBe([$heading])
        ->and(DB::table('exm_paper_items as i')->join('qb_question_versions as v', 'v.id', '=', 'i.version_id')->where('i.paper_id', $paper->id)->pluck('v.node_id')->unique()->all())->toBe([$child]);
});

test('what the bank cannot give is left as a gap, and said so', function () {
    [$exam, $paper, $url] = smallExam();
    $this->activeQuestion($this->node);
    $this->activeQuestion($this->node);

    fillPaper($url)->assertSessionHasNoErrors();

    expect(itemsOf($paper))->toHaveCount(2);
    $this->actingAs($this->setter)->get($url)->assertInertia(fn ($page) => $page
        ->where('totals.chosen', 2)
        ->where('totals.planned', 5)
        ->where('rows.0.missing', 1)
        ->where('rows.1.missing', 2)
        ->where('rows.1.available', 0));
});

test('the same text is never drawn twice', function () {
    [$exam, $paper, $url] = smallExam(['rows' => [['section' => null, 'node_id' => test()->node, 'question_type_id' => test()->typeId(), 'question_count' => 3, 'marks_each' => 1]]]);
    // Three questions with the very same wording, and two different ones.
    foreach (range(1, 3) as $i) {
        $this->activeQuestion($this->node, stem: 'Which drug is first line for anaphylaxis?');
    }
    $this->activeQuestion($this->node, stem: 'Which nerve is compressed in carpal tunnel syndrome?');
    $this->activeQuestion($this->node, stem: 'Which vessel supplies the sinoatrial node?');

    fillPaper($url)->assertSessionHasNoErrors();

    $hashes = DB::table('exm_paper_items as i')->join('qb_question_versions as v', 'v.id', '=', 'i.version_id')->where('i.paper_id', $paper->id)->pluck('v.content_hash');
    expect($hashes)->toHaveCount(3)->and($hashes->unique())->toHaveCount(3);
});

test('the draw reaches the mix the blueprint asks for', function () {
    $recall = $this->levelId('cognitive', 0);
    $application = $this->levelId('cognitive', 2);
    [$exam, $paper, $url] = paperExam([
        'rows' => [['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId(), 'question_count' => 10, 'marks_each' => 1]],
        'cognitive' => [['level_id' => $recall, 'percent' => 30], ['level_id' => $application, 'percent' => 70]],
    ], ['total_marks' => 10]);

    // The bank has far more of the wrong kind, and enough of both.
    foreach (range(1, 20) as $i) {
        $this->activeQuestion($this->node, cognitive: $recall);
    }
    foreach (range(1, 12) as $i) {
        $this->activeQuestion($this->node, cognitive: $application);
    }

    fillPaper($url)->assertSessionHasNoErrors();

    $counts = DB::table('exm_paper_items as i')->join('qb_question_versions as v', 'v.id', '=', 'i.version_id')->where('i.paper_id', $paper->id)
        ->selectRaw('v.cognitive_level_id AS level, COUNT(*) AS total')->groupBy('v.cognitive_level_id')->pluck('total', 'level')->all(); // raw-sql-reviewed: constant aggregate

    expect($counts[$recall])->toBe(3)->and($counts[$application])->toBe(7);

    $this->actingAs($this->setter)->get($url)->assertInertia(fn ($page) => $page
        ->where('mix.cognitive.0.target', 30)
        ->where('mix.cognitive.0.count', 3)
        ->where('mix.cognitive.2.actual', 70));
});

test('a row that can only give one level of thinking is drawn first, so the mix can still be reached', function () {
    $recall = $this->levelId('cognitive', 0);
    $application = $this->levelId('cognitive', 2);
    [$exam, $paper, $url] = paperExam([
        'rows' => [
            ['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId(), 'question_count' => 4, 'marks_each' => 1],
            ['section' => null, 'node_id' => $this->otherNode, 'question_type_id' => $this->typeId(), 'question_count' => 3, 'marks_each' => 1],
        ],
        'cognitive' => [['level_id' => $recall, 'percent' => 60], ['level_id' => $application, 'percent' => 40]],
    ], ['total_marks' => 7]);

    // The first topic has both kinds; the second only application questions.
    foreach (range(1, 8) as $i) {
        $this->activeQuestion($this->node, cognitive: $i <= 4 ? $recall : $application);
    }
    foreach (range(1, 5) as $i) {
        $this->activeQuestion($this->otherNode, cognitive: $application);
    }

    fillPaper($url)->assertSessionHasNoErrors();

    $levels = DB::table('exm_paper_items as i')->join('qb_question_versions as v', 'v.id', '=', 'i.version_id')->where('i.paper_id', $paper->id)->pluck('v.cognitive_level_id')->countBy()->all();
    expect($levels[$recall])->toBe(4)->and($levels[$application])->toBe(3);
});

test('questions never used are preferred, and those used lately come last', function () {
    [$exam, $paper, $url] = paperExam([
        'rows' => [['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId(), 'question_count' => 3, 'marks_each' => 1]],
    ], ['total_marks' => 3]);

    $fresh = collect(range(1, 3))->map(fn () => $this->activeQuestion($this->node)->question_id);
    $old = collect(range(1, 3))->map(fn () => $this->activeQuestion($this->node, timesUsed: 2, lastUsedAt: now()->subMonths(30)->toDateTimeString())->question_id);
    $lately = collect(range(1, 4))->map(fn () => $this->activeQuestion($this->node, timesUsed: 5, lastUsedAt: now()->subMonths(2)->toDateTimeString())->question_id);

    fillPaper($url)->assertSessionHasNoErrors();
    expect(itemsOf($paper)->pluck('question_id')->sort()->values()->all())->toBe($fresh->sort()->values()->all());

    // Asked for more, the ones used long ago come before those used lately.
    $second = $this->approvedExam(['rows' => [['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId(), 'question_count' => 5, 'marks_each' => 1]]], ['total_marks' => 5]);
    $this->actingAs($this->setter)->post("/exams/{$second->id}/paper");
    fillPaper("/exams/{$second->id}/paper")->assertSessionHasNoErrors();
    $picked = PaperItem::query()->where('paper_id', Paper::query()->where('examination_id', $second->id)->value('id'))->pluck('question_id');

    expect($picked->intersect($fresh))->toHaveCount(3)
        ->and($picked->intersect($old))->toHaveCount(2)
        ->and($picked->intersect($lately))->toHaveCount(0);
});

test('drawing the gaps keeps what is there, drawing again keeps only what is locked', function () {
    [$exam, $paper, $url] = smallExam();
    foreach (range(1, 8) as $i) {
        $this->activeQuestion($this->node);
        $this->activeQuestion($this->otherNode);
    }

    fillPaper($url)->assertSessionHasNoErrors();
    $first = itemsOf($paper);

    // Nothing is missing, so drawing the gaps changes nothing.
    fillPaper($url)->assertSessionHasNoErrors();
    expect(itemsOf($paper)->pluck('id')->all())->toBe($first->pluck('id')->all());

    // One question is taken out: only that gap is filled.
    $gone = $first->first();
    $this->actingAs($this->setter)->delete("{$url}/items/{$gone->id}")->assertSessionHasNoErrors();
    fillPaper($url)->assertSessionHasNoErrors();
    $after = itemsOf($paper);
    expect($after)->toHaveCount(5)
        ->and($after->pluck('id')->intersect($first->skip(1)->pluck('id')))->toHaveCount(4);

    // Locked ones survive a new draw; the rest may change.
    $locked = $after->first();
    $this->actingAs($this->setter)->post("{$url}/items/{$locked->id}/lock", ['locked' => true])->assertSessionHasNoErrors();
    fillPaper($url, 'redraw')->assertSessionHasNoErrors();

    $again = itemsOf($paper);
    expect($again)->toHaveCount(5)
        ->and($again->pluck('id'))->toContain($locked->id)
        ->and($again->where('id', '!=', $locked->id)->pluck('id')->intersect($after->where('id', '!=', $locked->id)->pluck('id')))->toHaveCount(0);
});

test('a question can be chosen by hand for a row that has room', function () {
    [$exam, $paper, $url] = smallExam();
    $mine = $this->activeQuestion($this->node);
    $other = $this->activeQuestion($this->otherNode);

    $row = ['node_id' => $this->node, 'question_type_id' => $this->typeId(), 'marks_each' => 1, 'section' => null];
    $this->actingAs($this->setter)->post("{$url}/items", $row + ['question_id' => $mine->question_id])->assertSessionHasNoErrors();

    $item = itemsOf($paper)->first();
    expect($item->source)->toBe('manual')
        ->and($item->version_id)->toBe($mine->id)
        ->and($item->marks)->toBe(1.0)
        ->and($item->picked_by)->toBe($this->setter->id);

    // Once, and only for a row it fits.
    $this->actingAs($this->setter)->from($url)->post("{$url}/items", $row + ['question_id' => $mine->question_id])->assertSessionHasErrors('question_id');
    $this->actingAs($this->setter)->from($url)->post("{$url}/items", $row + ['question_id' => $other->question_id])->assertSessionHasErrors('question_id');
    expect(itemsOf($paper))->toHaveCount(1)
        ->and(DB::table('sec_audit_logs')->where('action', 'paper.item_added')->count())->toBe(1);
});

test('a question that does not belong to the row is refused', function () {
    [$exam, $paper, $url] = smallExam();
    $row = ['node_id' => $this->node, 'question_type_id' => $this->typeId(), 'marks_each' => 1, 'section' => null];
    $add = fn (int $questionId) => $this->actingAs($this->setter)->from($url)->post("{$url}/items", $row + ['question_id' => $questionId]);

    $add($this->activeQuestion($this->node, examTypeId: $this->cmsExamType('supplementary'))->question_id)->assertSessionHasErrors('question_id');
    $add($this->activeQuestion($this->node, typeId: $this->typeId('short_answer'))->question_id)->assertSessionHasErrors('question_id');
    $add($this->activeQuestion($this->node, archived: true)->question_id)->assertSessionHasErrors('question_id');
    $add($this->activeQuestion($this->node, branchId: $this->cmsBranch('City Campus'))->question_id)->assertSessionHasErrors('question_id');
    $add(999999)->assertSessionHasErrors('question_id');

    // A row that is not in the blueprint.
    $this->actingAs($this->setter)->from($url)->post("{$url}/items", ['node_id' => $this->node, 'question_type_id' => $this->typeId(), 'marks_each' => 9, 'section' => null, 'question_id' => $this->activeQuestion($this->node)->question_id])
        ->assertSessionHasErrors('slot');

    expect(itemsOf($paper))->toHaveCount(0);
});

test('a full row takes no more, but a question can be swapped for another of the row', function () {
    [$exam, $paper, $url] = smallExam(['rows' => [['section' => null, 'node_id' => test()->node, 'question_type_id' => test()->typeId(), 'question_count' => 1, 'marks_each' => 1]]]);
    $first = $this->activeQuestion($this->node);
    $second = $this->activeQuestion($this->node);
    $third = $this->activeQuestion($this->node);
    $row = ['node_id' => $this->node, 'question_type_id' => $this->typeId(), 'marks_each' => 1, 'section' => null];

    $this->actingAs($this->setter)->post("{$url}/items", $row + ['question_id' => $first->question_id])->assertSessionHasNoErrors();
    $this->actingAs($this->setter)->from($url)->post("{$url}/items", $row + ['question_id' => $second->question_id])->assertSessionHasErrors('slot');

    $item = itemsOf($paper)->first();
    $this->actingAs($this->setter)->post("{$url}/items/{$item->id}/swap", ['question_id' => $second->question_id])->assertSessionHasNoErrors();
    expect($item->fresh()->question_id)->toBe($second->question_id)->and($item->fresh()->version_id)->toBe($second->id);

    // Not for one already in the paper.
    $this->actingAs($this->setter)->from($url)->post("{$url}/items/{$item->id}/swap", ['question_id' => $second->question_id])->assertSessionHasErrors('question_id');
    expect(DB::table('sec_audit_logs')->where('action', 'paper.item_swapped')->count())->toBe(1);
    unset($third);
});

test('a locked question can be neither swapped nor taken out until it is unlocked', function () {
    [$exam, $paper, $url] = smallExam();
    foreach (range(1, 4) as $i) {
        $this->activeQuestion($this->node);
    }
    $spare = $this->activeQuestion($this->node);
    fillPaper($url);
    $item = itemsOf($paper)->first();

    $this->actingAs($this->setter)->post("{$url}/items/{$item->id}/lock", ['locked' => true])->assertSessionHasNoErrors();
    expect($item->fresh()->is_locked)->toBeTrue();

    $this->actingAs($this->setter)->from($url)->delete("{$url}/items/{$item->id}")->assertSessionHasErrors('item');
    $this->actingAs($this->setter)->from($url)->post("{$url}/items/{$item->id}/swap", ['question_id' => $spare->question_id])->assertSessionHasErrors('item');
    expect(PaperItem::query()->whereKey($item->id)->exists())->toBeTrue();

    $this->actingAs($this->setter)->post("{$url}/items/{$item->id}/lock", ['locked' => false])->assertSessionHasNoErrors();
    $this->actingAs($this->setter)->delete("{$url}/items/{$item->id}")->assertSessionHasNoErrors();
    expect(PaperItem::query()->whereKey($item->id)->exists())->toBeFalse();

    // Taking one out closes the gap in the numbering.
    expect(itemsOf($paper)->pluck('position')->all())->toBe(range(1, itemsOf($paper)->count()));
    expect(DB::table('sec_audit_logs')->whereIn('action', ['paper.item_locked', 'paper.item_unlocked', 'paper.item_removed'])->count())->toBe(3);
});

test('an item of another paper cannot be reached through this one', function () {
    [$examOne, $paperOne, $urlOne] = smallExam();
    $this->activeQuestion($this->node);
    fillPaper($urlOne);
    $item = itemsOf($paperOne)->first();

    [$examTwo, , $urlTwo] = smallExam();
    $this->actingAs($this->setter)->delete("{$urlTwo}/items/{$item->id}")->assertNotFound();
    $this->actingAs($this->setter)->post("{$urlTwo}/items/{$item->id}/lock", ['locked' => true])->assertNotFound();
    expect(PaperItem::query()->whereKey($item->id)->exists())->toBeTrue();
});

test('the picker lists what a row could take, without what is already in the paper', function () {
    [$exam, $paper, $url] = smallExam();
    $chosen = $this->activeQuestion($this->node, stem: 'Which drug relieves the pain of an acute myocardial infarction?');
    $this->activeQuestion($this->node, stem: 'Which investigation confirms a pulmonary embolism?');
    $this->activeQuestion($this->node, timesUsed: 2, lastUsedAt: now()->subMonth()->toDateTimeString(), stem: 'Which sign suggests cardiac tamponade?');
    $this->activeQuestion($this->otherNode, stem: 'A question from the other topic.');
    $row = ['node_id' => $this->node, 'question_type_id' => $this->typeId(), 'marks_each' => 1];

    $this->actingAs($this->setter)->post("{$url}/items", $row + ['section' => null, 'question_id' => $chosen->question_id]);

    $this->actingAs($this->setter)->getJson("{$url}/candidates?".http_build_query($row))->assertOk()
        ->assertJsonCount(2, 'candidates')
        ->assertJsonMissing(['reference' => $chosen->question->public_ref]);

    $this->actingAs($this->setter)->getJson("{$url}/candidates?".http_build_query($row + ['search' => 'embolism']))->assertOk()
        ->assertJsonCount(1, 'candidates')
        ->assertJsonPath('candidates.0.summary', fn ($text) => str_contains($text, 'pulmonary embolism'));

    // The one used lately says so; nothing here gives the answer key away.
    $response = $this->actingAs($this->setter)->getJson("{$url}/candidates?".http_build_query($row))->json('candidates');
    expect(collect($response)->firstWhere('usedRecently', true))->not->toBeNull()
        ->and(json_encode($response))->not->toContain('is_correct');

    // A row that is not in the blueprint has no picker.
    $this->actingAs($this->setter)->getJson("{$url}/candidates?".http_build_query(['node_id' => $this->node, 'question_type_id' => $this->typeId(), 'marks_each' => 9]))->assertStatus(422);
});

test('the checks say what is worth a second look', function () {
    [$exam, $paper, $url] = smallExam(['rows' => [['section' => null, 'node_id' => test()->node, 'question_type_id' => test()->typeId(), 'question_count' => 5, 'marks_each' => 1]]]);
    $row = ['node_id' => $this->node, 'question_type_id' => $this->typeId(), 'marks_each' => 1, 'section' => null];
    $add = fn ($version) => $this->actingAs($this->setter)->post("{$url}/items", $row + ['question_id' => $version->question_id])->assertSessionHasNoErrors();

    // One question names the diagnosis another asks for.
    $key = $this->activeQuestion($this->node, stem: 'Which diagnosis fits a crushing chest pain with ST elevation?', options: [
        ['A', 'Acute myocardial infarction', true], ['B', 'Aortic stenosis', false],
    ]);
    $giver = $this->activeQuestion($this->node, stem: 'A man with acute myocardial infarction is given aspirin. What else is first line?', options: [
        ['A', 'Nitrates', true], ['B', 'Warfarin', false],
    ]);
    // Used lately, and written by the setter.
    $lately = $this->activeQuestion($this->node, timesUsed: 1, lastUsedAt: now()->subMonth()->toDateTimeString(), authorId: $this->setter->id);
    // The same wording twice: allowed by hand, and flagged.
    $twin = $this->activeQuestion($this->node, stem: 'Twin wording of a question');
    $twinToo = $this->activeQuestion($this->node, stem: 'Twin wording of a question');

    foreach ([$key, $giver, $lately, $twin, $twinToo] as $version) {
        $add($version);
    }

    $warnings = collect($this->actingAs($this->setter)->get($url)->viewData('page')['props']['warnings']);

    expect($warnings->pluck('kind')->all())->toContain('cue', 'recent', 'own', 'same_text');
    $cue = $warnings->firstWhere('kind', 'cue');
    expect($cue['references'][0])->toContain($giver->question->public_ref)->toContain('gives away')->toContain($key->question->public_ref)
        ->and($warnings->firstWhere('kind', 'same_text')['references'])->toHaveCount(2)
        ->and($warnings->firstWhere('kind', 'recent')['references'])->toBe([$lately->question->public_ref]);
});

test('a question that has a newer version, or has left the bank, is flagged', function () {
    [$exam, $paper, $url] = smallExam();
    $version = $this->activeQuestion($this->node);
    $leaving = $this->activeQuestion($this->node);
    $row = ['node_id' => $this->node, 'question_type_id' => $this->typeId(), 'marks_each' => 1, 'section' => null];
    foreach ([$version, $leaving] as $v) {
        $this->actingAs($this->setter)->post("{$url}/items", $row + ['question_id' => $v->question_id])->assertSessionHasNoErrors();
    }

    // A newer version takes over in the bank; the paper keeps the one it chose.
    DB::table('qb_question_versions')->where('id', $version->id)->update(['status' => 'superseded']);
    $next = $this->activeQuestion($this->node);
    DB::table('qb_questions')->where('id', $version->question_id)->update(['active_version_id' => $next->id]);
    DB::table('qb_questions')->where('id', $leaving->question_id)->update(['is_archived' => true]);

    $item = PaperItem::query()->where('paper_id', $paper->id)->where('question_id', $version->question_id)->firstOrFail();
    expect($item->version_id)->toBe($version->id);

    $this->actingAs($this->setter)->get($url)->assertInertia(fn ($page) => $page
        ->where('warnings', fn ($warnings) => collect($warnings)->pluck('kind')->contains('newer') && collect($warnings)->pluck('kind')->contains('gone'))
        ->where('rows.0.items.0.flags', fn ($flags) => collect($flags)->contains('newer'))
        ->where('rows.0.items.1.flags', fn ($flags) => collect($flags)->contains('gone')));
});

test('the reader of a paper sees the counts, not the questions', function () {
    [$exam, $paper, $url] = smallExam();
    foreach (range(1, 3) as $i) {
        $this->activeQuestion($this->node);
    }
    fillPaper($url);

    $viewerRole = $this->cmsRole('Registry');
    $this->cmsGrant($viewerRole, 'exam_papers', 'view');
    $viewer = $this->staffUser([$viewerRole], $this->branch);

    $this->actingAs($viewer)->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->where('mayRead', false)
        ->where('mayEdit', false)
        ->where('totals.chosen', 3)
        ->where('rows.0.items.0.summary', null)
        ->where('rows.0.available', null));

    // No changes, no picker, no draw.
    $this->actingAs($viewer)->post($url.'/fill', ['mode' => 'gaps'])->assertForbidden();
    $this->actingAs($viewer)->getJson($url.'/candidates?node_id='.$this->node.'&question_type_id='.$this->typeId().'&marks_each=1')->assertForbidden();
    $this->actingAs($viewer)->post($url.'/items', ['node_id' => $this->node, 'question_type_id' => $this->typeId(), 'marks_each' => 1, 'question_id' => 1])->assertForbidden();
    $this->actingAs($viewer)->delete($url.'/items/'.itemsOf($paper)->first()->id)->assertForbidden();
});

test('the paper is not reachable without the right, the course or the campus', function () {
    [$exam, $paper, $url] = smallExam();

    // A blueprint reader is not a paper reader.
    $this->actingAs($this->approver)->get($url)->assertForbidden();

    $none = $this->staffUser([$this->cmsRole('Cleaner')], $this->branch);
    $this->actingAs($none)->get($url)->assertForbidden();

    $limited = $this->staffUser([$this->setterRole], $this->branch);
    $this->cmsExamScope($limited, 'programme', $this->cmsProgramme($this->branch, 'BDS'));
    $this->actingAs($limited)->get($url)->assertForbidden();
    $this->actingAs($limited)->post($url.'/fill', ['mode' => 'gaps'])->assertForbidden();

    $elsewhere = $this->staffUser([$this->setterRole], $this->cmsBranch('City Campus'));
    app(AccessControl::class)->forget($elsewhere);
    $this->actingAs($elsewhere)->get($url)->assertNotFound();
    $this->actingAs($elsewhere)->post($url.'/fill', ['mode' => 'gaps'])->assertNotFound();
});

test('a reopened blueprint stops the paper being changed until it is approved again', function () {
    [$exam, $paper, $url] = smallExam();
    foreach (range(1, 5) as $i) {
        $this->activeQuestion($this->node);
        $this->activeQuestion($this->otherNode);
    }
    fillPaper($url);
    $before = itemsOf($paper)->pluck('id')->all();

    $bp = "/exams/{$exam->id}/blueprint";
    $this->actingAs($this->approver)->post($bp.'/reopen', ['reason' => 'The paper needs another section.'])->assertSessionHasNoErrors();

    $this->actingAs($this->setter)->get($url)->assertInertia(fn ($page) => $page->where('mayEdit', false)->where('blueprintApproved', false));
    fillPaper($url)->assertSessionHasErrors('paper');
    $this->actingAs($this->setter)->delete($url.'/items/'.$before[0])->assertSessionHasErrors('paper');
    expect(itemsOf($paper)->pluck('id')->all())->toBe($before);

    // Approved again with the same rows, the paper carries on, and says the blueprint was approved again.
    $this->actingAs($this->setter)->post($bp.'/submit')->assertSessionHasNoErrors();
    $this->actingAs($this->approver)->post($bp.'/approve')->assertSessionHasNoErrors();
    $this->actingAs($this->setter)->get($url)->assertInertia(fn ($page) => $page->where('mayEdit', true)->where('paper.blueprintChanged', false));
});

test('an item finds its row again when the blueprint is saved, and loses it when the row changes', function () {
    [$exam, $paper, $url] = smallExam();
    foreach (range(1, 5) as $i) {
        $this->activeQuestion($this->node);
        $this->activeQuestion($this->otherNode);
    }
    fillPaper($url);

    $bp = "/exams/{$exam->id}/blueprint";
    $this->actingAs($this->approver)->post($bp.'/reopen', ['reason' => 'Changing one row of the blueprint.'])->assertSessionHasNoErrors();

    // The first row is unchanged; the second now asks for one four-mark question (still 7 marks).
    $this->actingAs($this->setter)->put($bp, $this->blueprintPayload([
        'rows' => [
            ['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId(), 'question_count' => 3, 'marks_each' => 1],
            ['section' => null, 'node_id' => $this->otherNode, 'question_type_id' => $this->typeId(), 'question_count' => 1, 'marks_each' => 4],
        ],
    ]))->assertSessionHasNoErrors();
    $this->actingAs($this->setter)->post($bp.'/submit')->assertSessionHasNoErrors();
    $this->actingAs($this->approver)->post($bp.'/approve')->assertSessionHasNoErrors();

    // The blueprint was approved again with a change, and the paper says so.
    $this->actingAs($this->setter)->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->where('paper.blueprintChanged', true)
        ->where('rows.0.items', fn ($items) => count($items) === 3)
        ->where('rows.1.items', fn ($items) => count($items) === 0)
        ->where('unassigned', fn ($items) => count($items) === 2)
        ->where('warnings', fn ($warnings) => collect($warnings)->pluck('kind')->contains('unassigned')));
});

test('how each candidate meets the paper is saved and audited', function () {
    [$exam, $paper, $url] = smallExam();

    $this->actingAs($this->setter)->put($url, ['shuffle_questions' => false, 'shuffle_options' => true])->assertSessionHasNoErrors();
    expect($paper->fresh()->shuffle_questions)->toBeFalse()->and($paper->fresh()->shuffle_options)->toBeTrue();

    $change = DB::table('sec_audit_logs')->where('action', 'paper.settings_changed')->first();
    expect(json_decode((string) $change->old_values, true))->toMatchArray(['shuffle_questions' => true]);

    $this->actingAs($this->setter)->from($url)->put($url, ['shuffle_questions' => 'maybe'])->assertSessionHasErrors('shuffle_questions');
});

test('the examination page shows how far the paper has got', function () {
    $exam = $this->approvedExam(smallBlueprint(), ['total_marks' => 7]);
    $show = fn () => $this->actingAs($this->setter)->get("/exams/{$exam->id}")->assertOk();

    $show()->assertInertia(fn ($page) => $page->where('paper.exists', false)->where('paper.planned', 5)->where('canOpenPaper', true));

    $this->actingAs($this->setter)->post("/exams/{$exam->id}/paper");
    foreach (range(1, 3) as $i) {
        $this->activeQuestion($this->node);
    }
    fillPaper("/exams/{$exam->id}/paper");

    $show()->assertInertia(fn ($page) => $page->where('paper.exists', true)->where('paper.chosen', 3)->where('paper.marks', 3)->where('paper.plannedMarks', 7));
});

test('a paper item keeps the version that was chosen, whatever happens to the question', function () {
    [$exam, $paper, $url] = smallExam(['rows' => [['section' => null, 'node_id' => test()->node, 'question_type_id' => test()->typeId(), 'question_count' => 1, 'marks_each' => 1]]]);
    $version = $this->activeQuestion($this->node);
    fillPaper($url);

    // The database keeps the pinned version and the question from being deleted from under the paper.
    expect(itemsOf($paper)->first()->version_id)->toBe($version->id)
        ->and(fn () => DB::table('qb_question_versions')->where('id', $version->id)->delete())->toThrow(QueryException::class);
});
