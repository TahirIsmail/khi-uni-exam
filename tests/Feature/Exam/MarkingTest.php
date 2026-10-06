<?php

use App\Domain\Candidate\Actions\CheckInCandidate;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Candidate\Models\Centre;
use App\Domain\Candidate\Models\Room;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Marking\Queries\AdjudicationQueue;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsExaminations;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class, BuildsExaminations::class);

/**
 * An essay question with rubric criteria: BuildsExaminations::activeQuestion() has no rubric
 * support, and criteria can only be written while the version is still draft (the question bank's
 * own immutability rule), so this builds the version by hand, adds the criteria, then advances it
 * to active exactly the way activeQuestion() does for its own options.
 */
function essayQuestionWithRubric(): QuestionVersion
{
    $t = test();
    $author = User::factory()->create();
    $question = Question::factory()->create([
        'branch_id' => $t->branch, 'course_id' => $t->course, 'latest_version_no' => 1,
        'is_archived' => false, 'times_used' => 0, 'created_by' => $author->id,
    ]);
    $text = 'Discuss the management of '.bin2hex(random_bytes(6)).'.';
    $version = QuestionVersion::factory()->create([
        'question_id' => $question->id, 'question_type_id' => $t->typeId('essay'),
        'branch_id' => $t->branch, 'course_id' => $t->course, 'node_id' => $t->node,
        'exam_type_id' => $t->annual, 'stem' => "<p>{$text}</p>",
        'content_hash' => hash('sha256', $text), 'search_text' => $text,
        'status' => 'draft', 'author_id' => $author->id, 'created_by' => $author->id,
    ]);

    DB::table('qb_question_rubric_criteria')->insert([
        ['version_id' => $version->id, 'sort_order' => 1, 'criterion' => 'Diagnosis', 'max_marks' => 6, 'guidance' => null, 'created_at' => now(), 'updated_at' => now()],
        ['version_id' => $version->id, 'sort_order' => 2, 'criterion' => 'Management', 'max_marks' => 4, 'guidance' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);

    foreach (['submitted', 'under_review', 'approved', 'active'] as $status) {
        DB::table('qb_question_versions')->where('id', $version->id)->update(['status' => $status]);
    }
    $question->update(['active_version_id' => $version->id]);

    return $version->refresh();
}

beforeEach(function () {
    $this->examWorld();

    $this->exam = $this->approvedExam(
        ['rows' => [
            ['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId('single_best_answer'), 'question_count' => 1, 'marks_each' => 2],
            ['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId('essay'), 'question_count' => 1, 'marks_each' => 10],
        ]],
        ['total_marks' => 12],
    );

    $sba = $this->activeQuestion($this->node, $this->typeId('single_best_answer'), null, false, null, null, null, null, null, null, null, [
        ['A', 'Wrong', false],
        ['B', 'Right', true],
        ['C', 'Wrong', false],
    ]);
    $this->correctOptionId = (int) DB::table('qb_question_options')->where('version_id', $sba->id)->where('is_correct', true)->value('id');

    $essay = essayQuestionWithRubric();
    $this->essayCriteria = DB::table('qb_question_rubric_criteria')->where('version_id', $essay->id)->orderBy('sort_order')->get();

    $paperUrl = "/exams/{$this->exam->id}/paper";
    $this->actingAs($this->setter)->post($paperUrl)->assertSessionHasNoErrors();
    $this->actingAs($this->setter)->post("{$paperUrl}/fill", ['mode' => 'gaps'])->assertSessionHasNoErrors();
    $this->actingAs($this->setter)->post("{$paperUrl}/submit")->assertSessionHasNoErrors();
    $this->actingAs($this->approver)->post("{$paperUrl}/approve")->assertSessionHasNoErrors();
    $this->actingAs($this->approver)->post("{$paperUrl}/finalise")->assertSessionHasNoErrors();

    $publisherRole = $this->cmsRole('Controller');
    $this->cmsGrant($publisherRole, 'exam_papers', 'view');
    $this->cmsGrant($publisherRole, 'exam_papers_publish', 'view');
    $this->publisher = $this->staffUser([$publisherRole], $this->branch);
    $this->actingAs($this->publisher, 'web')->post("{$paperUrl}/publish")->assertSessionHasNoErrors();

    $this->conductRole = $this->cmsRole('Conduct officer');
    $this->cmsGrant($this->conductRole, 'exam_candidates', 'view', 'edit');
    $this->cmsGrant($this->conductRole, 'exam_centres', 'view', 'edit');
    $this->cmsGrant($this->conductRole, 'exam_allocation', 'view');
    $this->cmsGrant($this->conductRole, 'exam_checkin', 'view');
    $this->conductOfficer = $this->staffUser([$this->conductRole], $this->branch);

    $this->markingRole = $this->cmsRole('Examiner');
    $this->cmsGrant($this->markingRole, 'exam_marking', 'view');
    $this->examiner1 = $this->staffUser([$this->markingRole], $this->branch);
    $this->examiner2 = $this->staffUser([$this->markingRole], $this->branch);

    $this->assignRole = $this->cmsRole('Marking controller');
    $this->cmsGrant($this->assignRole, 'exam_marking_assign', 'view');
    $this->assigner = $this->staffUser([$this->assignRole], $this->branch);

    $this->adjudicateRole = $this->cmsRole('Adjudicator role');
    $this->cmsGrant($this->adjudicateRole, 'exam_marking_adjudicate', 'view');
    $this->adjudicator = $this->staffUser([$this->adjudicateRole], $this->branch);

    $this->actingAs($this->conductOfficer, 'web')->post('/exams/conduct/centres', ['name' => 'Hall', 'code' => 'H-'.Str::random(6)])->assertSessionHasNoErrors();
    $this->centre = Centre::query()->latest('id')->firstOrFail();
    $this->actingAs($this->conductOfficer, 'web')->post("/exams/conduct/centres/{$this->centre->id}/rooms", ['name' => 'R1', 'capacity' => 10])->assertSessionHasNoErrors();
    $this->room = Room::query()->latest('id')->firstOrFail();

    $this->actingAs($this->conductOfficer, 'web')->post("/exams/{$this->exam->id}/candidates/import", [
        'file' => UploadedFile::fake()->createWithContent('c.csv', "candidate_no,name\nC-001,Ayesha Khan"),
    ])->assertSessionHasNoErrors();
    $this->candidate = Candidate::query()->where('candidate_no', 'C-001')->firstOrFail();
    $this->actingAs($this->conductOfficer, 'web')->post("/exams/{$this->exam->id}/candidates/{$this->candidate->id}/allocate", ['room_id' => $this->room->id])->assertSessionHasNoErrors();

    $checkedIn = app(CheckInCandidate::class)($this->conductOfficer, $this->exam, $this->candidate->refresh());
    $this->candidate = $checkedIn['candidate'];
    $this->pin = $checkedIn['pin'];

    // Assign both examiners before the candidate sits, so RecordExaminerMark finds them assigned.
    $this->actingAs($this->assigner, 'web')->post("/marking/{$this->exam->id}/examiners", ['user_id' => $this->examiner1->id, 'role' => 'first'])->assertSessionHasNoErrors();
    $this->actingAs($this->assigner, 'web')->post("/marking/{$this->exam->id}/examiners", ['user_id' => $this->examiner2->id, 'role' => 'second'])->assertSessionHasNoErrors();
    $this->actingAs($this->assigner, 'web')->post("/marking/{$this->exam->id}/examiners", ['user_id' => $this->adjudicator->id, 'role' => 'adjudicator'])->assertSessionHasNoErrors();
});

function sitAndSubmit(): CandidateExam
{
    $t = test();
    $t->post('/sit/'.$t->exam->sit_code, ['candidate_no' => 'C-001', 'pin' => $t->pin]);
    $attempt = CandidateExam::query()->where('candidate_id', $t->candidate->id)->firstOrFail();
    $items = $attempt->items()->with('paperItem')->get();

    $sbaItem = $items->firstWhere('paperItem.question_type_id', $t->typeId('single_best_answer'));
    $essayItem = $items->firstWhere('paperItem.question_type_id', $t->typeId('essay'));

    $t->post("/sit/{$t->exam->sit_code}/answer", ['item_id' => $sbaItem->id, 'sequence' => 1, 'payload' => ['selected' => [$t->correctOptionId]]]);
    $t->post("/sit/{$t->exam->sit_code}/answer", ['item_id' => $essayItem->id, 'sequence' => 2, 'payload' => ['text' => 'A reasonable essay answer.']]);
    $t->post("/sit/{$t->exam->sit_code}/submit");

    return $attempt->fresh();
}

test('an objective item is auto-marked on submission, and an essay is left for an examiner', function () {
    $attempt = sitAndSubmit();
    $items = $attempt->items()->with('paperItem')->get();
    $sbaItem = $items->firstWhere('paperItem.question_type_id', $this->typeId('single_best_answer'));
    $essayItem = $items->firstWhere('paperItem.question_type_id', $this->typeId('essay'));

    $auto = DB::table('mrk_item_marks')->where('cand_paper_item_id', $sbaItem->id)->where('source', 'auto')->first();
    expect($auto)->not->toBeNull()
        ->and((float) $auto->marks_awarded)->toBe(2.0);

    expect(DB::table('mrk_item_marks')->where('cand_paper_item_id', $essayItem->id)->exists())->toBeFalse();
});

test('single-marking: the first examiner\'s mark is the final mark, with no second examiner needed', function () {
    $attempt = sitAndSubmit();
    $essayItem = $attempt->items()->with('paperItem')->get()->firstWhere('paperItem.question_type_id', $this->typeId('essay'));

    $this->actingAs($this->examiner1, 'web')->post("/marking/{$this->exam->id}/items/{$essayItem->id}/mark", [
        'marks_awarded' => 8,
        'criteria' => [
            ['rubric_criterion_id' => $this->essayCriteria[0]->id, 'marks_awarded' => 5],
            ['rubric_criterion_id' => $this->essayCriteria[1]->id, 'marks_awarded' => 3],
        ],
    ])->assertSessionHasNoErrors();

    expect(DB::table('mrk_item_marks')->where('cand_paper_item_id', $essayItem->id)->where('source', 'examiner_1')->value('marks_awarded'))->toEqualWithDelta(8.0, 0.001)
        ->and(DB::table('mrk_item_marks')->where('cand_paper_item_id', $essayItem->id)->where('source', 'final')->exists())->toBeFalse();
});

test('double-marking within the threshold finalises to the examiners\' average', function () {
    DB::table('exm_examinations')->where('id', $this->exam->id)->update(['require_double_marking' => true]);
    $attempt = sitAndSubmit();
    $essayItem = $attempt->items()->with('paperItem')->get()->firstWhere('paperItem.question_type_id', $this->typeId('essay'));

    $this->actingAs($this->examiner1, 'web')->post("/marking/{$this->exam->id}/items/{$essayItem->id}/mark", [
        'marks_awarded' => 8,
        'criteria' => [
            ['rubric_criterion_id' => $this->essayCriteria[0]->id, 'marks_awarded' => 5],
            ['rubric_criterion_id' => $this->essayCriteria[1]->id, 'marks_awarded' => 3],
        ],
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->examiner2, 'web')->post("/marking/{$this->exam->id}/items/{$essayItem->id}/mark", [
        'marks_awarded' => 7,
        'criteria' => [
            ['rubric_criterion_id' => $this->essayCriteria[0]->id, 'marks_awarded' => 4],
            ['rubric_criterion_id' => $this->essayCriteria[1]->id, 'marks_awarded' => 3],
        ],
    ])->assertSessionHasNoErrors();

    expect(DB::table('mrk_item_marks')->where('cand_paper_item_id', $essayItem->id)->where('source', 'final')->value('marks_awarded'))->toEqualWithDelta(7.5, 0.001);
});

test('double-marking beyond the threshold is pending until an adjudicator decides', function () {
    DB::table('exm_examinations')->where('id', $this->exam->id)->update(['require_double_marking' => true]);
    $attempt = sitAndSubmit();
    $essayItem = $attempt->items()->with('paperItem')->get()->firstWhere('paperItem.question_type_id', $this->typeId('essay'));

    $this->actingAs($this->examiner1, 'web')->post("/marking/{$this->exam->id}/items/{$essayItem->id}/mark", ['marks_awarded' => 9, 'criteria' => []]);
    $this->actingAs($this->examiner2, 'web')->post("/marking/{$this->exam->id}/items/{$essayItem->id}/mark", ['marks_awarded' => 3, 'criteria' => []]);

    expect(DB::table('mrk_item_marks')->where('cand_paper_item_id', $essayItem->id)->where('source', 'final')->exists())->toBeFalse();

    $queue = app(AdjudicationQueue::class)->forExamination($this->exam->fresh());
    expect($queue)->toHaveCount(1);

    $this->actingAs($this->adjudicator, 'web')->post("/marking/{$this->exam->id}/items/{$essayItem->id}/adjudicate", [
        'marks_awarded' => 4,
        'reason' => 'The second examiner\'s reading of the answer is the sounder one.',
    ])->assertSessionHasNoErrors();

    expect(DB::table('mrk_item_marks')->where('cand_paper_item_id', $essayItem->id)->where('source', 'adjudicator')->value('marks_awarded'))->toEqualWithDelta(4.0, 0.001);
});

test('rubric criteria must add up to the mark given', function () {
    $attempt = sitAndSubmit();
    $essayItem = $attempt->items()->with('paperItem')->get()->firstWhere('paperItem.question_type_id', $this->typeId('essay'));

    $this->actingAs($this->examiner1, 'web')->post("/marking/{$this->exam->id}/items/{$essayItem->id}/mark", [
        'marks_awarded' => 8,
        'criteria' => [
            ['rubric_criterion_id' => $this->essayCriteria[0]->id, 'marks_awarded' => 5],
            ['rubric_criterion_id' => $this->essayCriteria[1]->id, 'marks_awarded' => 1],
        ],
    ])->assertInvalid(['criteria']);
});

test('an examiner cannot see their peer\'s mark before submitting their own', function () {
    DB::table('exm_examinations')->where('id', $this->exam->id)->update(['require_double_marking' => true]);
    $attempt = sitAndSubmit();
    $essayItem = $attempt->items()->with('paperItem')->get()->firstWhere('paperItem.question_type_id', $this->typeId('essay'));

    $this->actingAs($this->examiner1, 'web')->post("/marking/{$this->exam->id}/items/{$essayItem->id}/mark", ['marks_awarded' => 8, 'criteria' => []]);

    $page = $this->actingAs($this->examiner2, 'web')->get("/marking/{$this->exam->id}/items/{$essayItem->id}");
    $page->assertOk();
    $marks = collect($page->viewData('page')['props']['marks']);
    expect($marks->pluck('source'))->not->toContain('examiner_1');
});

test('a mark, once recorded, is never changed or deleted', function () {
    $attempt = sitAndSubmit();
    $essayItem = $attempt->items()->with('paperItem')->get()->firstWhere('paperItem.question_type_id', $this->typeId('essay'));
    $this->actingAs($this->examiner1, 'web')->post("/marking/{$this->exam->id}/items/{$essayItem->id}/mark", ['marks_awarded' => 8, 'criteria' => []]);
    $markId = DB::table('mrk_item_marks')->where('cand_paper_item_id', $essayItem->id)->value('id');

    expect(fn () => DB::table('mrk_item_marks')->where('id', $markId)->update(['marks_awarded' => 1]))
        ->toThrow(QueryException::class, 'never changed')
        ->and(fn () => DB::table('mrk_item_marks')->where('id', $markId)->delete())
        ->toThrow(QueryException::class, 'never deleted');
});

test('only an assigned examiner may mark, and only a marking.assign holder may assign', function () {
    $attempt = sitAndSubmit();
    $essayItem = $attempt->items()->with('paperItem')->get()->firstWhere('paperItem.question_type_id', $this->typeId('essay'));

    $strangerRole = $this->cmsRole('Not an examiner');
    $this->cmsGrant($strangerRole, 'exam_marking', 'view');
    $stranger = $this->staffUser([$strangerRole], $this->branch);

    // Nothing connects this person to the examination — not a teaching assignment, not an
    // appointment — so it is not theirs to see, never mind mark.
    $this->actingAs($stranger, 'web')->post("/marking/{$this->exam->id}/items/{$essayItem->id}/mark", ['marks_awarded' => 5, 'criteria' => []])
        ->assertNotFound();

    // A teacher of the programme does see it, and is still refused until they are appointed.
    $this->cmsTeaches($stranger, $this->exam->programme_id, (int) $this->exam->intake_id);
    $this->actingAs($stranger, 'web')->post("/marking/{$this->exam->id}/items/{$essayItem->id}/mark", ['marks_awarded' => 5, 'criteria' => []])
        ->assertInvalid(['examiner']);

    $this->actingAs($this->examiner1, 'web')->post("/marking/{$this->exam->id}/examiners", ['user_id' => $this->examiner2->id, 'role' => 'first'])
        ->assertForbidden();
});
