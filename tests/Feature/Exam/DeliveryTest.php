<?php

use App\Domain\Candidate\Actions\CheckInCandidate;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Candidate\Models\Centre;
use App\Domain\Candidate\Models\Room;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Delivery\Models\DeliverySession;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsExaminations;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class, BuildsExaminations::class);

beforeEach(function () {
    $this->examWorld();

    $this->exam = $this->approvedExam(
        ['rows' => [['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId(), 'question_count' => 3, 'marks_each' => 1]]],
        ['total_marks' => 3],
    );
    foreach (range(1, 3) as $i) {
        $this->activeQuestion($this->node);
    }

    $paperUrl = "/exams/{$this->exam->id}/paper";
    $this->actingAs($this->setter)->post($paperUrl)->assertSessionHasNoErrors();
    $this->actingAs($this->setter)->post("{$paperUrl}/fill", ['mode' => 'gaps'])->assertSessionHasNoErrors();
    $this->actingAs($this->setter)->post("{$paperUrl}/submit")->assertSessionHasNoErrors();
    $this->actingAs($this->approver)->post("{$paperUrl}/approve")->assertSessionHasNoErrors();
    $this->actingAs($this->approver)->post("{$paperUrl}/finalise")->assertSessionHasNoErrors();

    $this->publisherRole = $this->cmsRole('Controller');
    $this->cmsGrant($this->publisherRole, 'exam_papers', 'view');
    $this->cmsGrant($this->publisherRole, 'exam_papers_publish', 'view');
    $this->publisher = $this->staffUser([$this->publisherRole], $this->branch);
    $this->actingAs($this->publisher)->post("{$paperUrl}/publish")->assertSessionHasNoErrors();

    $this->conductRole = $this->cmsRole('Conduct officer');
    $this->cmsGrant($this->conductRole, 'exam_candidates', 'view', 'edit');
    $this->cmsGrant($this->conductRole, 'exam_centres', 'view', 'edit');
    $this->cmsGrant($this->conductRole, 'exam_allocation', 'view');
    $this->cmsGrant($this->conductRole, 'exam_checkin', 'view');
    $this->conductOfficer = $this->staffUser([$this->conductRole], $this->branch);

    $this->invigilatorRole = $this->cmsRole('Invigilator');
    $this->cmsGrant($this->invigilatorRole, 'exam_monitor', 'view');
    $this->cmsGrant($this->invigilatorRole, 'exam_session_control', 'view');
    $this->invigilator = $this->staffUser([$this->invigilatorRole], $this->branch);

    $this->actingAs($this->conductOfficer)->post('/exams/conduct/centres', ['name' => 'Hall', 'code' => 'H-'.Str::random(6)])->assertSessionHasNoErrors();
    $this->centre = Centre::query()->latest('id')->firstOrFail();
    $this->actingAs($this->conductOfficer)->post("/exams/conduct/centres/{$this->centre->id}/rooms", ['name' => 'R1', 'capacity' => 10])->assertSessionHasNoErrors();
    $this->room = Room::query()->latest('id')->firstOrFail();

    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/candidates/import", [
        'file' => UploadedFile::fake()->createWithContent('c.csv', "candidate_no,name\nC-001,Ayesha Khan"),
    ])->assertSessionHasNoErrors();
    $this->candidate = Candidate::query()->where('candidate_no', 'C-001')->firstOrFail();
    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/candidates/{$this->candidate->id}/allocate", ['room_id' => $this->room->id])->assertSessionHasNoErrors();

    // Checked in through the action itself, not the HTTP endpoint, so the plaintext PIN — shown to
    // the invigilator exactly once, by design — is in hand for the tests below.
    $checkedIn = app(CheckInCandidate::class)($this->conductOfficer, $this->exam, $this->candidate->refresh());
    $this->candidate = $checkedIn['candidate'];
    $this->pin = $checkedIn['pin'];
});

function signInPin(string $candidateNo, string $pin): TestResponse
{
    return test()->post('/sit/'.test()->exam->id, ['candidate_no' => $candidateNo, 'pin' => $pin]);
}

test('a candidate signs in with their number and PIN, and the exam is assigned', function () {
    signInPin('C-001', $this->pin)->assertRedirect("/sit/{$this->exam->id}/exam");

    $attempt = CandidateExam::query()->where('candidate_id', $this->candidate->id)->firstOrFail();
    expect($attempt->status)->toBe(AttemptStatus::InProgress)
        ->and($attempt->started_at)->not->toBeNull()
        ->and($attempt->deadline_at)->not->toBeNull()
        ->and($attempt->items()->count())->toBe(3)
        ->and(DeliverySession::query()->where('candidate_exam_id', $attempt->id)->whereNull('ended_at')->count())->toBe(1);
});

test('a wrong PIN is refused the same way an unknown candidate number is', function () {
    signInPin('C-001', '000000')->assertInvalid(['pin']);
    signInPin('C-999', $this->pin)->assertInvalid(['pin']);
});

test('signing in again while the first computer is still active is blocked', function () {
    signInPin('C-001', $this->pin)->assertRedirect();

    signInPin('C-001', $this->pin)->assertInvalid(['session']);

    $attempt = CandidateExam::query()->where('candidate_id', $this->candidate->id)->firstOrFail();
    expect(DeliverySession::query()->where('candidate_exam_id', $attempt->id)->whereNull('ended_at')->count())->toBe(1);
});

test('a computer silent for a while lets the next sign-in resume automatically', function () {
    signInPin('C-001', $this->pin)->assertRedirect();
    $attempt = CandidateExam::query()->where('candidate_id', $this->candidate->id)->firstOrFail();
    $firstSession = DeliverySession::query()->where('candidate_exam_id', $attempt->id)->firstOrFail();
    $firstSession->update(['last_heartbeat_at' => now()->subSeconds(120)]);

    signInPin('C-001', $this->pin)->assertRedirect("/sit/{$this->exam->id}/exam");

    expect($firstSession->fresh()->ended_at)->not->toBeNull()
        ->and($firstSession->fresh()->end_reason)->toBe('replaced')
        ->and(DeliverySession::query()->where('candidate_exam_id', $attempt->id)->whereNull('ended_at')->count())->toBe(1)
        ->and(DB::table('sec_audit_logs')->where('action', 'candidate.device_changed')->count())->toBe(1);
});

test('an invigilator can end an open session so the candidate may resume elsewhere', function () {
    signInPin('C-001', $this->pin)->assertRedirect();
    $attempt = CandidateExam::query()->where('candidate_id', $this->candidate->id)->firstOrFail();

    signInPin('C-001', $this->pin)->assertInvalid(['session']);

    $this->actingAs($this->invigilator)->post("/exams/{$this->exam->id}/monitor/attempts/{$attempt->id}/end-session")->assertSessionHasNoErrors();

    signInPin('C-001', $this->pin)->assertRedirect("/sit/{$this->exam->id}/exam");
});

test('ending a session needs its own right, separate from just monitoring', function () {
    signInPin('C-001', $this->pin)->assertRedirect();
    $attempt = CandidateExam::query()->where('candidate_id', $this->candidate->id)->firstOrFail();

    $viewOnlyRole = $this->cmsRole('Viewer only');
    $this->cmsGrant($viewOnlyRole, 'exam_monitor', 'view');
    $viewer = $this->staffUser([$viewOnlyRole], $this->branch);

    $this->actingAs($viewer)->get("/exams/{$this->exam->id}/monitor")->assertOk();
    $this->actingAs($viewer)->post("/exams/{$this->exam->id}/monitor/attempts/{$attempt->id}/end-session")->assertForbidden();
});

test('an answer is saved, and resending the same sequence number changes nothing', function () {
    signInPin('C-001', $this->pin);
    $attempt = CandidateExam::query()->where('candidate_id', $this->candidate->id)->firstOrFail();
    $item = $attempt->items()->orderBy('position')->firstOrFail();

    $answer = ['selected' => [1]];
    $this->post("/sit/{$this->exam->id}/answer", ['item_id' => $item->id, 'sequence' => 1, 'payload' => $answer])->assertOk();
    $this->post("/sit/{$this->exam->id}/answer", ['item_id' => $item->id, 'sequence' => 1, 'payload' => ['selected' => [2]]])->assertOk();

    expect(DB::table('dlv_answer_events')->where('candidate_exam_id', $attempt->id)->count())->toBe(1);
    $current = DB::table('dlv_answers_current')->where('candidate_exam_id', $attempt->id)->where('cand_paper_item_id', $item->id)->first();
    expect(json_decode((string) $current->payload, true))->toBe($answer);
});

test('an older, out-of-order retry never overwrites a newer answer', function () {
    signInPin('C-001', $this->pin);
    $attempt = CandidateExam::query()->where('candidate_id', $this->candidate->id)->firstOrFail();
    $item = $attempt->items()->orderBy('position')->firstOrFail();

    $this->post("/sit/{$this->exam->id}/answer", ['item_id' => $item->id, 'sequence' => 2, 'payload' => ['selected' => [2]]])->assertOk();
    $this->post("/sit/{$this->exam->id}/answer", ['item_id' => $item->id, 'sequence' => 1, 'payload' => ['selected' => [1]]])->assertOk();

    $current = DB::table('dlv_answers_current')->where('candidate_exam_id', $attempt->id)->where('cand_paper_item_id', $item->id)->first();
    expect(json_decode((string) $current->payload, true))->toBe(['selected' => [2]]);
});

test('the candidate submits, and no more answers can be recorded', function () {
    signInPin('C-001', $this->pin);
    $attempt = CandidateExam::query()->where('candidate_id', $this->candidate->id)->firstOrFail();
    $item = $attempt->items()->orderBy('position')->firstOrFail();

    $this->post("/sit/{$this->exam->id}/submit")->assertRedirect("/sit/{$this->exam->id}/submitted");

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Submitted)
        ->and(DB::table('dlv_submissions')->where('candidate_exam_id', $attempt->id)->exists())->toBeTrue()
        ->and(DeliverySession::query()->where('candidate_exam_id', $attempt->id)->whereNull('ended_at')->count())->toBe(0);

    $this->post("/sit/{$this->exam->id}/answer", ['item_id' => $item->id, 'sequence' => 1, 'payload' => ['selected' => [1]]])
        ->assertRedirect("/sit/{$this->exam->id}");
});

test('signing in once submitted opens the submitted screen, not the exam', function () {
    signInPin('C-001', $this->pin);
    $this->post("/sit/{$this->exam->id}/submit");

    signInPin('C-001', $this->pin)->assertRedirect("/sit/{$this->exam->id}/submitted");
});

test('the database refuses to record the same answer sequence number twice', function () {
    signInPin('C-001', $this->pin);
    $attempt = CandidateExam::query()->where('candidate_id', $this->candidate->id)->firstOrFail();
    $item = $attempt->items()->orderBy('position')->firstOrFail();

    DB::table('dlv_answer_events')->insert(['candidate_exam_id' => $attempt->id, 'cand_paper_item_id' => $item->id, 'sequence_no' => 1, 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => DB::table('dlv_answer_events')->insert(['candidate_exam_id' => $attempt->id, 'cand_paper_item_id' => $item->id, 'sequence_no' => 1, 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
});

test('the database refuses to change a candidate\'s paper once the attempt has started', function () {
    signInPin('C-001', $this->pin);
    $attempt = CandidateExam::query()->where('candidate_id', $this->candidate->id)->firstOrFail();
    $paperItem = DB::table('exm_paper_items')->where('paper_id', $this->exam->fresh()->id)->value('id');

    expect(fn () => DB::table('cand_paper_items')->insert(['candidate_exam_id' => $attempt->id, 'paper_item_id' => $paperItem, 'position' => 99, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class, 'has started');
});

test('an answer event is never changed or deleted, and an attempt is never deleted', function () {
    signInPin('C-001', $this->pin);
    $attempt = CandidateExam::query()->where('candidate_id', $this->candidate->id)->firstOrFail();
    $item = $attempt->items()->orderBy('position')->firstOrFail();
    $this->post("/sit/{$this->exam->id}/answer", ['item_id' => $item->id, 'sequence' => 1, 'payload' => ['selected' => [1]]]);

    expect(fn () => DB::table('dlv_answer_events')->where('candidate_exam_id', $attempt->id)->update(['sequence_no' => 5]))
        ->toThrow(QueryException::class, 'never changed')
        ->and(fn () => DB::table('dlv_answer_events')->where('candidate_exam_id', $attempt->id)->delete())
        ->toThrow(QueryException::class, 'never deleted')
        ->and(fn () => DB::table('cand_candidate_exams')->where('id', $attempt->id)->delete())
        ->toThrow(QueryException::class, 'never deleted');
});

test('pausing a room freezes every deadline in it, and resuming restores the time lost', function () {
    signInPin('C-001', $this->pin);
    $attempt = CandidateExam::query()->where('candidate_id', $this->candidate->id)->firstOrFail();
    $originalDeadline = $attempt->deadline_at;

    $this->actingAs($this->invigilator)->post("/exams/{$this->exam->id}/monitor/rooms/{$this->room->id}/pause")->assertSessionHasNoErrors();
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Paused)
        ->and($attempt->fresh()->paused_at)->not->toBeNull()
        ->and($attempt->fresh()->deadline_at->equalTo($originalDeadline))->toBeTrue();

    // Answers cannot be saved while the room is paused.
    $item = $attempt->items()->orderBy('position')->firstOrFail();
    $this->post("/sit/{$this->exam->id}/answer", ['item_id' => $item->id, 'sequence' => 1, 'payload' => ['selected' => [1]]])->assertInvalid(['attempt']);

    DB::table('cand_candidate_exams')->where('id', $attempt->id)->update(['paused_at' => now()->subMinutes(5)]);
    $this->actingAs($this->invigilator)->post("/exams/{$this->exam->id}/monitor/rooms/{$this->room->id}/resume")->assertSessionHasNoErrors();

    expect($attempt->fresh()->status)->toBe(AttemptStatus::InProgress)
        ->and($attempt->fresh()->paused_at)->toBeNull()
        ->and($originalDeadline->diffInSeconds($attempt->fresh()->deadline_at))->toBeGreaterThanOrEqual(299);
});

test('compensating time is added to one candidate\'s deadline, with a reason', function () {
    signInPin('C-001', $this->pin);
    $attempt = CandidateExam::query()->where('candidate_id', $this->candidate->id)->firstOrFail();
    $originalDeadline = $attempt->deadline_at;

    $this->actingAs($this->invigilator)->post("/exams/{$this->exam->id}/monitor/attempts/{$attempt->id}/add-time", [
        'minutes' => 10,
        'reason' => 'The workstation had to be restarted.',
    ])->assertSessionHasNoErrors();

    expect((int) $originalDeadline->diffInSeconds($attempt->fresh()->deadline_at))->toBe(600);

    $this->actingAs($this->invigilator)->post("/exams/{$this->exam->id}/monitor/attempts/{$attempt->id}/add-time", ['minutes' => 10])
        ->assertInvalid(['reason']);
});

test('once the deadline and its grace period have passed, the next request submits the attempt', function () {
    signInPin('C-001', $this->pin);
    $attempt = CandidateExam::query()->where('candidate_id', $this->candidate->id)->firstOrFail();
    DB::table('cand_candidate_exams')->where('id', $attempt->id)->update(['deadline_at' => now()->subMinutes(10)]);

    $response = $this->postJson("/sit/{$this->exam->id}/heartbeat");
    $response->assertOk();

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Submitted)
        ->and($response->json('status'))->toBe('submitted');
});
