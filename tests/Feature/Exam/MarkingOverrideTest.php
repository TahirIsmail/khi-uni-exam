<?php

use App\Domain\Candidate\Actions\CheckInCandidate;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Candidate\Models\Centre;
use App\Domain\Candidate\Models\Room;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Marking\Queries\AttemptMarkSheet;
use App\Domain\Results\Models\Result;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsExaminations;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class, BuildsExaminations::class);

/*
 * Overturning a mark the computer made.
 *
 * The marking queue never lists a machine-marked item, and rightly — an examiner does not want two
 * hundred settled MCQs in the pile they have to get through. But when a candidate queries question
 * 14, somebody must be able to reach it, look at it, and say the computer got it wrong. That is what
 * the attempt screen is for.
 *
 * Nothing is edited: mrk_item_marks is append-only, so the examiner's mark is a second row that
 * FinalMark prefers, and the machine's original stays exactly where it was.
 */

beforeEach(function () {
    $this->examWorld();

    $this->exam = $this->approvedExam(
        ['rows' => [
            ['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId('single_best_answer'), 'question_count' => 1, 'marks_each' => 2],
        ]],
        ['total_marks' => 2],
    );

    $sba = $this->activeQuestion($this->node, $this->typeId('single_best_answer'), null, false, null, null, null, null, null, null, null, [
        ['A', 'Wrong', false],
        ['B', 'Right', true],
    ]);
    $this->correctOptionId = (int) DB::table('qb_question_options')->where('version_id', $sba->id)->where('is_correct', true)->value('id');

    $paperUrl = "/exams/{$this->exam->id}/paper";
    $this->actingAs($this->setter)->post($paperUrl)->assertSessionHasNoErrors();
    $this->actingAs($this->setter)->post("{$paperUrl}/fill", ['mode' => 'gaps'])->assertSessionHasNoErrors();
    $this->actingAs($this->setter)->post("{$paperUrl}/submit")->assertSessionHasNoErrors();
    $this->actingAs($this->approver)->post("{$paperUrl}/approve")->assertSessionHasNoErrors();
    $this->actingAs($this->approver)->post("{$paperUrl}/finalise")->assertSessionHasNoErrors();

    $controllerRole = $this->cmsRole('Controller');
    $this->cmsGrant($controllerRole, 'exam_papers', 'view');
    $this->cmsGrant($controllerRole, 'exam_papers_publish', 'view');
    $this->cmsGrant($controllerRole, 'exam_results', 'view');
    $this->controller = $this->staffUser([$controllerRole], $this->branch);
    $this->actingAs($this->controller, 'web')->post("{$paperUrl}/publish")->assertSessionHasNoErrors();

    $conductRole = $this->cmsRole('Conduct officer');
    $this->cmsGrant($conductRole, 'exam_candidates', 'view', 'edit');
    $this->cmsGrant($conductRole, 'exam_centres', 'view', 'edit');
    $this->cmsGrant($conductRole, 'exam_allocation', 'view');
    $this->cmsGrant($conductRole, 'exam_checkin', 'view');
    $conductOfficer = $this->staffUser([$conductRole], $this->branch);

    $markingRole = $this->cmsRole('Examiner');
    $this->cmsGrant($markingRole, 'exam_marking', 'view');
    $this->examiner = $this->staffUser([$markingRole], $this->branch);

    $adjudicateRole = $this->cmsRole('Adjudicator role');
    $this->cmsGrant($adjudicateRole, 'exam_marking_adjudicate', 'view');
    $this->adjudicator = $this->staffUser([$adjudicateRole], $this->branch);

    $assignRole = $this->cmsRole('Marking controller');
    $this->cmsGrant($assignRole, 'exam_marking_assign', 'view');
    $assigner = $this->staffUser([$assignRole], $this->branch);

    $this->actingAs($conductOfficer, 'web')->post('/exams/conduct/centres', ['name' => 'Hall', 'code' => 'H-'.Str::random(6)])->assertSessionHasNoErrors();
    $centre = Centre::query()->latest('id')->firstOrFail();
    $this->actingAs($conductOfficer, 'web')->post("/exams/conduct/centres/{$centre->id}/rooms", ['name' => 'R1', 'capacity' => 10])->assertSessionHasNoErrors();
    $room = Room::query()->latest('id')->firstOrFail();

    $this->actingAs($conductOfficer, 'web')->post("/exams/{$this->exam->id}/candidates/import", [
        'file' => UploadedFile::fake()->createWithContent('c.csv', "candidate_no,name\nC-001,Ayesha Khan"),
    ])->assertSessionHasNoErrors();
    $candidate = Candidate::query()->where('candidate_no', 'C-001')->firstOrFail();
    $this->actingAs($conductOfficer, 'web')->post("/exams/{$this->exam->id}/candidates/{$candidate->id}/allocate", ['room_id' => $room->id])->assertSessionHasNoErrors();

    $checkedIn = app(CheckInCandidate::class)($conductOfficer, $this->exam, $candidate->refresh());
    $this->candidate = $checkedIn['candidate'];
    $this->pin = $checkedIn['pin'];

    $this->actingAs($assigner, 'web')->post("/marking/{$this->exam->id}/examiners", ['user_id' => $this->examiner->id, 'role' => 'first'])->assertSessionHasNoErrors();
    $this->actingAs($assigner, 'web')->post("/marking/{$this->exam->id}/examiners", ['user_id' => $this->adjudicator->id, 'role' => 'adjudicator'])->assertSessionHasNoErrors();

    // A second examination, only so a test can try to reach this one's attempt through it. Bare —
    // it needs no paper.
    $this->otherExam = $this->newExam();
});

/** Sits the one-question paper, answering it correctly, and returns the submitted attempt. */
function sitTheMcq(): CandidateExam
{
    $t = test();

    $t->post('/sit/'.$t->exam->id, ['candidate_no' => 'C-001', 'pin' => $t->pin]);
    $attempt = CandidateExam::query()->where('candidate_id', $t->candidate->id)->firstOrFail();

    $item = $attempt->items()->with('paperItem')->get()->first();
    $t->post("/sit/{$t->exam->id}/answer", ['item_id' => $item->id, 'sequence' => 1, 'payload' => ['selected' => [$t->correctOptionId]]]);
    $t->post("/sit/{$t->exam->id}/submit");

    $t->mcqItem = $item;

    return $attempt->fresh();
}

test('an examiner may record a mark over one the computer settled, and theirs is what counts', function () {
    sitTheMcq();

    expect((float) DB::table('mrk_item_marks')->where('cand_paper_item_id', $this->mcqItem->id)->where('source', 'auto')->value('marks_awarded'))
        ->toBe(2.0);

    $this->actingAs($this->examiner, 'web')->post("/marking/{$this->exam->id}/items/{$this->mcqItem->id}/mark", [
        'marks_awarded' => 0,
        'criteria' => [],
        'comments' => 'Option B was ambiguous as printed; the board directed a nil.',
    ])->assertSessionHasNoErrors();

    $marks = DB::table('mrk_item_marks')->where('cand_paper_item_id', $this->mcqItem->id)->get()->keyBy('source');

    // Both rows survive: the record says what the machine thought and what the person decided.
    expect($marks)->toHaveCount(2)
        ->and((float) $marks['auto']->marks_awarded)->toBe(2.0)
        ->and((float) $marks['examiner_1']->marks_awarded)->toBe(0.0);
});

test('changing a machine mark without saying why is refused', function () {
    sitTheMcq();

    $this->actingAs($this->examiner, 'web')
        ->post("/marking/{$this->exam->id}/items/{$this->mcqItem->id}/mark", ['marks_awarded' => 0, 'criteria' => []])
        ->assertSessionHasErrors('comments');

    expect(DB::table('mrk_item_marks')->where('cand_paper_item_id', $this->mcqItem->id)->where('source', 'examiner_1')->exists())
        ->toBeFalse();
});

test('an overridden mark carries through to the result', function () {
    $attempt = sitTheMcq();

    $this->actingAs($this->examiner, 'web')->post("/marking/{$this->exam->id}/items/{$this->mcqItem->id}/mark", [
        'marks_awarded' => 0, 'criteria' => [], 'comments' => 'Answered under a misprint.',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->controller, 'web')->get("/results/{$this->exam->id}")->assertOk();

    expect((float) Result::query()->where('candidate_exam_id', $attempt->id)->value('total_marks'))->toBe(0.0);
});

test('the attempt screen lists the whole paper and says which marks came from the computer', function () {
    $attempt = sitTheMcq();

    $sheet = app(AttemptMarkSheet::class)->for($attempt);

    expect($sheet['candidateNo'])->toBe('C-001')
        ->and($sheet['name'])->toBe('Ayesha Khan')
        ->and($sheet['items'])->toHaveCount(1)
        ->and($sheet['items'][0]['isMachineMark'])->toBeTrue()
        ->and($sheet['items'][0]['awarded'])->toEqualWithDelta(2.0, 0.001)
        ->and($sheet['items'][0]['awaiting'])->toBeFalse();
});

test('the attempt screen is reachable by an examiner and refused to somebody who cannot mark', function () {
    $attempt = sitTheMcq();

    $this->actingAs($this->examiner, 'web')
        ->get("/marking/{$this->exam->id}/attempts/{$attempt->id}")
        ->assertOk();

    // The adjudicator holds marking.adjudicate, not marking.mark.
    $this->actingAs($this->adjudicator, 'web')
        ->get("/marking/{$this->exam->id}/attempts/{$attempt->id}")
        ->assertForbidden();
});

test('an attempt belonging to another examination is not reachable through this one', function () {
    $attempt = sitTheMcq();

    $this->actingAs($this->examiner, 'web')
        ->get("/marking/{$this->otherExam->id}/attempts/{$attempt->id}")
        ->assertNotFound();
});
