<?php

use App\Domain\Candidate\Actions\CheckInCandidate;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Candidate\Models\CandidateDevice;
use App\Domain\Candidate\Models\Centre;
use App\Domain\Candidate\Models\Room;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Models\CandidateExam;
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

    $this->proctorRole = $this->cmsRole('Proctoring committee');
    $this->cmsGrant($this->proctorRole, 'proctor_events', 'view');
    $this->cmsGrant($this->proctorRole, 'proctor_decisions', 'view');
    $this->proctor = $this->staffUser([$this->proctorRole], $this->branch);

    $this->actingAs($this->conductOfficer)->post('/exams/conduct/centres', ['name' => 'Hall', 'code' => 'H-'.Str::random(6)])->assertSessionHasNoErrors();
    $this->centre = Centre::query()->latest('id')->firstOrFail();
    $this->actingAs($this->conductOfficer)->post("/exams/conduct/centres/{$this->centre->id}/rooms", ['name' => 'R1', 'capacity' => 10])->assertSessionHasNoErrors();
    $this->room = Room::query()->latest('id')->firstOrFail();

    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/candidates/import", [
        'file' => UploadedFile::fake()->createWithContent('c.csv', "candidate_no,name\nC-001,Ayesha Khan"),
    ])->assertSessionHasNoErrors();
    $this->candidate = Candidate::query()->where('candidate_no', 'C-001')->firstOrFail();
    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/candidates/{$this->candidate->id}/allocate", ['room_id' => $this->room->id])->assertSessionHasNoErrors();

    $checkedIn = app(CheckInCandidate::class)($this->conductOfficer, $this->exam, $this->candidate->refresh());
    $this->candidate = $checkedIn['candidate'];
    $this->pin = $checkedIn['pin'];
});

function proctoringSignIn(string $candidateNo, string $pin): TestResponse
{
    return test()->post('/sit/'.test()->exam->sit_code, ['candidate_no' => $candidateNo, 'pin' => $pin]);
}

test('a candidate\'s browser reporting a lockdown event records it with the right severity', function () {
    proctoringSignIn('C-001', $this->pin);
    $attempt = CandidateExam::query()->where('candidate_id', $this->candidate->id)->firstOrFail();

    $this->postJson("/sit/{$this->exam->sit_code}/proctor-event", ['type' => 'right_click'])->assertOk();
    $this->postJson("/sit/{$this->exam->sit_code}/proctor-event", ['type' => 'devtools_opened'])->assertOk();

    expect(DB::table('dlv_proctor_events')->where('candidate_exam_id', $attempt->id)->where('type', 'right_click')->value('severity'))->toBe('low')
        ->and(DB::table('dlv_proctor_events')->where('candidate_exam_id', $attempt->id)->where('type', 'devtools_opened')->value('severity'))->toBe('high')
        ->and(DB::table('sec_audit_logs')->where('action', 'proctor.event_recorded')->count())->toBe(1);
});

test('a device not seen before at this centre is pending until an invigilator approves it', function () {
    proctoringSignIn('C-001', $this->pin);

    $this->postJson("/sit/{$this->exam->sit_code}/device", ['fingerprint' => 'browser-a'])
        ->assertOk()->assertJson(['status' => 'pending']);

    $device = CandidateDevice::query()->where('centre_id', $this->centre->id)->firstOrFail();
    expect($device->isApproved())->toBeFalse();

    $this->actingAs($this->conductOfficer, 'web')->post("/exams/conduct/centres/{$this->centre->id}/devices/{$device->id}/approve")->assertSessionHasNoErrors();

    $this->postJson("/sit/{$this->exam->sit_code}/device", ['fingerprint' => 'browser-a'])
        ->assertOk()->assertJson(['status' => 'approved']);
});

test('approving a device needs centre.manage, not just centre.view', function () {
    proctoringSignIn('C-001', $this->pin);
    $this->postJson("/sit/{$this->exam->sit_code}/device", ['fingerprint' => 'browser-a']);
    $device = CandidateDevice::query()->where('centre_id', $this->centre->id)->firstOrFail();

    $viewOnlyRole = $this->cmsRole('Centre viewer');
    $this->cmsGrant($viewOnlyRole, 'exam_centres', 'view');
    $viewer = $this->staffUser([$viewOnlyRole], $this->branch);

    $this->actingAs($viewer, 'web')->get("/exams/conduct/centres/{$this->centre->id}/devices")->assertOk();
    $this->actingAs($viewer, 'web')->post("/exams/conduct/centres/{$this->centre->id}/devices/{$device->id}/approve")->assertForbidden();
});

test('a committee decision is recorded, and voiding an attempt transitions its status', function () {
    proctoringSignIn('C-001', $this->pin);
    $attempt = CandidateExam::query()->where('candidate_id', $this->candidate->id)->firstOrFail();
    $this->postJson("/sit/{$this->exam->sit_code}/proctor-event", ['type' => 'devtools_opened']);

    $this->actingAs($this->proctor, 'web')->post("/exams/{$this->exam->id}/proctoring/{$attempt->id}/decide", [
        'decision' => 'void_attempt',
        'reason' => 'Devtools opened repeatedly; the candidate admitted to looking up an answer.',
    ])->assertSessionHasNoErrors();

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Voided)
        ->and(DB::table('dlv_proctor_decisions')->where('candidate_exam_id', $attempt->id)->where('decision', 'void_attempt')->exists())->toBeTrue()
        ->and(DB::table('sec_audit_logs')->where('action', 'proctor.decision_recorded')->count())->toBe(1);
});

test('deciding a case needs proctor.review.decide, not just proctor.events.view', function () {
    proctoringSignIn('C-001', $this->pin);
    $attempt = CandidateExam::query()->where('candidate_id', $this->candidate->id)->firstOrFail();

    $viewOnlyRole = $this->cmsRole('Proctoring viewer');
    $this->cmsGrant($viewOnlyRole, 'proctor_events', 'view');
    $viewer = $this->staffUser([$viewOnlyRole], $this->branch);

    $this->actingAs($viewer, 'web')->get("/exams/{$this->exam->id}/proctoring")->assertOk();
    $this->actingAs($viewer, 'web')->post("/exams/{$this->exam->id}/proctoring/{$attempt->id}/decide", [
        'decision' => 'no_action',
        'reason' => 'Nothing further to add.',
    ])->assertForbidden();
});

test('a proctoring event is never changed or deleted, and neither is a decision', function () {
    proctoringSignIn('C-001', $this->pin);
    $attempt = CandidateExam::query()->where('candidate_id', $this->candidate->id)->firstOrFail();
    $this->postJson("/sit/{$this->exam->sit_code}/proctor-event", ['type' => 'tab_hidden']);

    expect(fn () => DB::table('dlv_proctor_events')->where('candidate_exam_id', $attempt->id)->update(['severity' => 'high']))
        ->toThrow(QueryException::class, 'never changed')
        ->and(fn () => DB::table('dlv_proctor_events')->where('candidate_exam_id', $attempt->id)->delete())
        ->toThrow(QueryException::class, 'never deleted');

    $this->actingAs($this->proctor, 'web')->post("/exams/{$this->exam->id}/proctoring/{$attempt->id}/decide", [
        'decision' => 'no_action',
        'reason' => 'Reviewed, no further action.',
    ]);
    $decisionId = DB::table('dlv_proctor_decisions')->where('candidate_exam_id', $attempt->id)->value('id');

    expect(fn () => DB::table('dlv_proctor_decisions')->where('id', $decisionId)->update(['reason' => 'changed']))
        ->toThrow(QueryException::class, 'never changed');
});

test('voiding an attempt is allowed even after it has been submitted', function () {
    proctoringSignIn('C-001', $this->pin);
    $attempt = CandidateExam::query()->where('candidate_id', $this->candidate->id)->firstOrFail();
    $this->post("/sit/{$this->exam->sit_code}/submit");
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Submitted);

    $this->actingAs($this->proctor, 'web')->post("/exams/{$this->exam->id}/proctoring/{$attempt->id}/decide", [
        'decision' => 'void_attempt',
        'reason' => 'Evidence surfaced after marking began.',
    ])->assertSessionHasNoErrors();

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Voided);
});
