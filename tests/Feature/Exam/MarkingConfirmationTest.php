<?php

use App\Domain\Candidate\Actions\CheckInCandidate;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Candidate\Models\Centre;
use App\Domain\Candidate\Models\Room;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Marking\Queries\MarkingQueue;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\Results\Models\Result;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsExaminations;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class, BuildsExaminations::class);

/**
 * A short-answer question with a list of accepted answers. BuildsExaminations::activeQuestion()
 * cannot attach those, and they can only be written while the version is still draft, so this builds
 * the version by hand and then advances it exactly as that helper does.
 */
function shortAnswerQuestion(): QuestionVersion
{
    $t = test();
    $author = User::factory()->create();
    $question = Question::factory()->create([
        'branch_id' => $t->branch, 'course_id' => $t->course, 'latest_version_no' => 1,
        'is_archived' => false, 'times_used' => 0, 'created_by' => $author->id,
    ]);
    $text = 'Name the vessel that '.bin2hex(random_bytes(6)).'.';
    $version = QuestionVersion::factory()->create([
        'question_id' => $question->id, 'question_type_id' => $t->typeId('short_answer'),
        'branch_id' => $t->branch, 'course_id' => $t->course, 'node_id' => $t->node,
        'exam_type_id' => $t->annual, 'stem' => "<p>{$text}</p>",
        'content_hash' => hash('sha256', $text), 'search_text' => $text,
        'status' => 'draft', 'author_id' => $author->id, 'created_by' => $author->id,
    ]);

    DB::table('qb_question_answers')->insert([
        'version_id' => $version->id, 'match_mode' => 'exact', 'answer_text' => 'portal vein',
        'case_sensitive' => 0, 'marks_fraction' => 1, 'sort_order' => 1,
        'created_at' => now(), 'updated_at' => now(),
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
            ['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId('short_answer'), 'question_count' => 1, 'marks_each' => 3],
        ]],
        ['total_marks' => 5],
    );

    $sba = $this->activeQuestion($this->node, $this->typeId('single_best_answer'), null, false, null, null, null, null, null, null, null, [
        ['A', 'Wrong', false],
        ['B', 'Right', true],
    ]);
    $this->correctOptionId = (int) DB::table('qb_question_options')->where('version_id', $sba->id)->where('is_correct', true)->value('id');

    shortAnswerQuestion();

    $paperUrl = "/exams/{$this->exam->id}/paper";
    $this->actingAs($this->setter)->post($paperUrl)->assertSessionHasNoErrors();
    $this->actingAs($this->setter)->post("{$paperUrl}/fill", ['mode' => 'gaps'])->assertSessionHasNoErrors();
    $this->actingAs($this->setter)->post("{$paperUrl}/submit")->assertSessionHasNoErrors();
    $this->actingAs($this->approver)->post("{$paperUrl}/approve")->assertSessionHasNoErrors();
    $this->actingAs($this->approver)->post("{$paperUrl}/finalise")->assertSessionHasNoErrors();

    $publisherRole = $this->cmsRole('Controller');
    $this->cmsGrant($publisherRole, 'exam_papers', 'view');
    $this->cmsGrant($publisherRole, 'exam_papers_publish', 'view');
    // The same person reads the results screen below, which is what recompiles them.
    $this->cmsGrant($publisherRole, 'exam_results', 'view');
    $this->publisher = $this->staffUser([$publisherRole], $this->branch);
    $this->actingAs($this->publisher, 'web')->post("{$paperUrl}/publish")->assertSessionHasNoErrors();

    $conductRole = $this->cmsRole('Conduct officer');
    $this->cmsGrant($conductRole, 'exam_candidates', 'view', 'edit');
    $this->cmsGrant($conductRole, 'exam_centres', 'view', 'edit');
    $this->cmsGrant($conductRole, 'exam_allocation', 'view');
    $this->cmsGrant($conductRole, 'exam_checkin', 'view');
    $this->conductOfficer = $this->staffUser([$conductRole], $this->branch);

    $markingRole = $this->cmsRole('Examiner');
    $this->cmsGrant($markingRole, 'exam_marking', 'view');
    $this->examiner = $this->staffUser([$markingRole], $this->branch);

    $assignRole = $this->cmsRole('Marking controller');
    $this->cmsGrant($assignRole, 'exam_marking_assign', 'view');
    $this->assigner = $this->staffUser([$assignRole], $this->branch);

    $this->actingAs($this->conductOfficer, 'web')->post('/exams/conduct/centres', ['name' => 'Hall', 'code' => 'H-'.Str::random(6)])->assertSessionHasNoErrors();
    $centre = Centre::query()->latest('id')->firstOrFail();
    $this->actingAs($this->conductOfficer, 'web')->post("/exams/conduct/centres/{$centre->id}/rooms", ['name' => 'R1', 'capacity' => 10])->assertSessionHasNoErrors();
    $room = Room::query()->latest('id')->firstOrFail();

    $this->actingAs($this->conductOfficer, 'web')->post("/exams/{$this->exam->id}/candidates/import", [
        'file' => UploadedFile::fake()->createWithContent('c.csv', "candidate_no,name\nC-001,Ayesha Khan"),
    ])->assertSessionHasNoErrors();
    $this->candidate = Candidate::query()->where('candidate_no', 'C-001')->firstOrFail();
    $this->actingAs($this->conductOfficer, 'web')->post("/exams/{$this->exam->id}/candidates/{$this->candidate->id}/allocate", ['room_id' => $room->id])->assertSessionHasNoErrors();

    $checkedIn = app(CheckInCandidate::class)($this->conductOfficer, $this->exam, $this->candidate->refresh());
    $this->candidate = $checkedIn['candidate'];
    $this->pin = $checkedIn['pin'];

    $this->actingAs($this->assigner, 'web')->post("/marking/{$this->exam->id}/examiners", ['user_id' => $this->examiner->id, 'role' => 'first'])->assertSessionHasNoErrors();
});

/** Sits the paper, answering the short answer with whatever text is given. */
function sitWithShortAnswer(string $typed): CandidateExam
{
    $t = test();
    $t->post('/sit/'.$t->exam->id, ['candidate_no' => 'C-001', 'pin' => $t->pin]);
    $attempt = CandidateExam::query()->where('candidate_id', $t->candidate->id)->firstOrFail();
    $items = $attempt->items()->with('paperItem')->get();

    $sba = $items->firstWhere('paperItem.question_type_id', $t->typeId('single_best_answer'));
    $short = $items->firstWhere('paperItem.question_type_id', $t->typeId('short_answer'));

    $t->post("/sit/{$t->exam->id}/answer", ['item_id' => $sba->id, 'sequence' => 1, 'payload' => ['selected' => [$t->correctOptionId]]]);
    $t->post("/sit/{$t->exam->id}/answer", ['item_id' => $short->id, 'sequence' => 2, 'payload' => ['text' => $typed]]);
    $t->post("/sit/{$t->exam->id}/submit");

    $t->shortItem = $short;
    $t->sbaItem = $sba;

    return $attempt->fresh();
}

test('a short answer is scored on submission but recorded as a suggestion only', function () {
    sitWithShortAnswer('portal vein');

    $auto = DB::table('mrk_item_marks')->where('cand_paper_item_id', $this->shortItem->id)->where('source', 'auto')->first();

    expect($auto)->not->toBeNull()
        ->and((float) $auto->marks_awarded)->toBe(3.0)
        ->and((bool) $auto->is_provisional)->toBeTrue();

    // The MCQ beside it is settled outright — only typed text waits for a person.
    $sbaAuto = DB::table('mrk_item_marks')->where('cand_paper_item_id', $this->sbaItem->id)->where('source', 'auto')->first();
    expect((bool) $sbaAuto->is_provisional)->toBeFalse();
});

test('an unconfirmed short answer leaves the result pending, however well it matched', function () {
    $attempt = sitWithShortAnswer('portal vein');

    $this->actingAs($this->publisher, 'web')->get("/results/{$this->exam->id}")->assertOk();

    $result = Result::query()->where('candidate_exam_id', $attempt->id)->firstOrFail();

    expect($result->pending_items)->toBeTrue()
        ->and((float) $result->total_marks)->toBe(0.0)
        ->and($result->grade)->toBeNull();
});

test('the examiner sees the short answer in their queue, carrying what the computer made of it', function () {
    sitWithShortAnswer('portal vein');

    $queue = app(MarkingQueue::class)->forExaminer($this->exam, $this->examiner);
    $row = collect($queue)->firstWhere('itemId', $this->shortItem->id);

    expect($row)->not->toBeNull()
        ->and($row['needsConfirming'])->toBeTrue()
        ->and($row['suggestedMarks'])->toEqualWithDelta(3.0, 0.001);

    // The MCQ is nobody's to mark, so it stays out of the queue.
    expect(collect($queue)->firstWhere('itemId', $this->sbaItem->id))->toBeNull();
});

test('confirming the suggestion completes the result', function () {
    $attempt = sitWithShortAnswer('portal vein');

    $this->actingAs($this->examiner, 'web')
        ->post("/marking/{$this->exam->id}/items/{$this->shortItem->id}/mark", ['marks_awarded' => 3, 'criteria' => []])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->publisher, 'web')->get("/results/{$this->exam->id}")->assertOk();
    $result = Result::query()->where('candidate_exam_id', $attempt->id)->firstOrFail();

    expect($result->pending_items)->toBeFalse()
        ->and((float) $result->total_marks)->toBe(5.0)
        ->and((float) $result->percentage)->toBe(100.0);
});

test('the examiner can give a different mark from the one suggested, and theirs is what counts', function () {
    // Right answer, spelled the way nobody put in the accepted list: the computer says nothing,
    // the examiner says otherwise. This is the whole reason the confirmation step exists.
    $attempt = sitWithShortAnswer('the portal venous vessel');

    expect((float) DB::table('mrk_item_marks')->where('cand_paper_item_id', $this->shortItem->id)->where('source', 'auto')->value('marks_awarded'))
        ->toBe(0.0);

    $this->actingAs($this->examiner, 'web')
        ->post("/marking/{$this->exam->id}/items/{$this->shortItem->id}/mark", ['marks_awarded' => 3, 'criteria' => []])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->publisher, 'web')->get("/results/{$this->exam->id}")->assertOk();

    expect((float) Result::query()->where('candidate_exam_id', $attempt->id)->value('total_marks'))->toBe(5.0);

    // The computer's nil stays on the record beside it; nothing is overwritten.
    expect(DB::table('mrk_item_marks')->where('cand_paper_item_id', $this->shortItem->id)->count())->toBe(2);
});

test('confirming a short answer needs no reason, unlike overturning a machine mark', function () {
    sitWithShortAnswer('portal vein');

    $this->actingAs($this->examiner, 'web')
        ->post("/marking/{$this->exam->id}/items/{$this->shortItem->id}/mark", ['marks_awarded' => 2, 'criteria' => []])
        ->assertSessionHasNoErrors();
});

test('the marking screen shows the examiner the answer that was wanted', function () {
    sitWithShortAnswer('portal vein');

    $this->actingAs($this->examiner, 'web')
        ->get("/marking/{$this->exam->id}/items/{$this->shortItem->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('item.needsConfirming', true)
            ->where('item.acceptedAnswers.0.answerText', 'portal vein')
        );
});
