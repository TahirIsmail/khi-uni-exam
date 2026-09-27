<?php

use App\Domain\Candidate\Actions\CheckInCandidate;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Candidate\Models\Centre;
use App\Domain\Candidate\Models\Room;
use App\Domain\Marking\Queries\ExaminerCandidates;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsExaminations;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class, BuildsExaminations::class);

/**
 * Who sees what on the Marking screen. The rule: a Super Admin and whoever appoints examiners see
 * the campus; an appointed examiner sees what they were appointed to; everybody else sees the
 * programmes and intakes they are assigned to teach in kmu-cms, and nothing else.
 */
beforeEach(function () {
    $this->examWorld();

    $this->exam = $this->approvedExam(
        ['rows' => [['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId('single_best_answer'), 'question_count' => 1, 'marks_each' => 2]]],
        ['total_marks' => 2],
    );

    $this->activeQuestion($this->node, $this->typeId('single_best_answer'), null, false, null, null, null, null, null, null, null, [
        ['A', 'Wrong', false],
        ['B', 'Right', true],
    ]);

    $paperUrl = "/exams/{$this->exam->id}/paper";
    $this->actingAs($this->setter, 'web')->post($paperUrl)->assertSessionHasNoErrors();
    $this->actingAs($this->setter, 'web')->post("{$paperUrl}/fill", ['mode' => 'gaps'])->assertSessionHasNoErrors();
    $this->actingAs($this->setter, 'web')->post("{$paperUrl}/submit")->assertSessionHasNoErrors();
    $this->actingAs($this->approver, 'web')->post("{$paperUrl}/approve")->assertSessionHasNoErrors();
    $this->actingAs($this->approver, 'web')->post("{$paperUrl}/finalise")->assertSessionHasNoErrors();

    $publisherRole = $this->cmsRole('Publisher');
    $this->cmsGrant($publisherRole, 'exam_papers', 'view');
    $this->cmsGrant($publisherRole, 'exam_papers_publish', 'view');
    $this->actingAs($this->staffUser([$publisherRole], $this->branch), 'web')->post("{$paperUrl}/publish")->assertSessionHasNoErrors();

    // One candidate, checked in, who sits and submits — the list only shows examinations with
    // something submitted.
    $conductRole = $this->cmsRole('Conduct officer');
    $this->cmsGrant($conductRole, 'exam_candidates', 'view', 'edit');
    $this->cmsGrant($conductRole, 'exam_centres', 'view', 'edit');
    $this->cmsGrant($conductRole, 'exam_allocation', 'view');
    $this->cmsGrant($conductRole, 'exam_checkin', 'view');
    $officer = $this->staffUser([$conductRole], $this->branch);

    $this->actingAs($officer, 'web')->post('/exams/conduct/centres', ['name' => 'Hall', 'code' => 'H-'.Str::random(6)])->assertSessionHasNoErrors();
    $centre = Centre::query()->latest('id')->firstOrFail();
    $this->actingAs($officer, 'web')->post("/exams/conduct/centres/{$centre->id}/rooms", ['name' => 'R1', 'capacity' => 10])->assertSessionHasNoErrors();
    $room = Room::query()->latest('id')->firstOrFail();

    $this->actingAs($officer, 'web')->post("/exams/{$this->exam->id}/candidates/import", [
        'file' => UploadedFile::fake()->createWithContent('c.csv', "candidate_no,name\nC-001,Ayesha Khan"),
    ])->assertSessionHasNoErrors();
    $candidate = Candidate::query()->where('candidate_no', 'C-001')->firstOrFail();
    $this->actingAs($officer, 'web')->post("/exams/{$this->exam->id}/candidates/{$candidate->id}/allocate", ['room_id' => $room->id])->assertSessionHasNoErrors();

    $checkedIn = app(CheckInCandidate::class)($officer, $this->exam, $candidate->refresh());
    $this->post('/sit/'.$this->exam->id, ['candidate_no' => 'C-001', 'pin' => $checkedIn['pin']]);
    $this->post("/sit/{$this->exam->id}/submit");

    $this->markerRole = $this->cmsRole('Examiner');
    $this->cmsGrant($this->markerRole, 'exam_marking', 'view');

    $this->assignerRole = $this->cmsRole('Marking controller');
    $this->cmsGrant($this->assignerRole, 'exam_marking_assign', 'view');
});

test('a teacher sees only the examinations of the programmes and intakes they teach', function () {
    $teacher = $this->staffUser([$this->markerRole], $this->branch);

    $this->actingAs($teacher, 'web')->get('/marking')
        ->assertInertia(fn ($page) => $page->component('marking/Index')
            ->where('examinations', [])
            ->where('scopedByTeaching', true)
            ->where('hasTeachingAssignments', false));

    $this->cmsTeaches($teacher, (int) $this->exam->programme_id, (int) $this->exam->intake_id);

    $this->actingAs($teacher, 'web')->get('/marking')
        ->assertInertia(fn ($page) => $page->component('marking/Index')
            ->has('examinations', 1)
            ->where('examinations.0.id', $this->exam->id)
            ->where('hasTeachingAssignments', true));
});

test('a teacher of another programme sees nothing, and cannot open the examination by its address', function () {
    $otherProgramme = $this->cmsProgramme($this->branch, 'BDS');
    $teacher = $this->staffUser([$this->markerRole], $this->branch);
    $this->cmsTeaches($teacher, $otherProgramme, (int) $this->exam->intake_id);

    $this->actingAs($teacher, 'web')->get('/marking')
        ->assertInertia(fn ($page) => $page->where('examinations', []));

    $this->actingAs($teacher, 'web')->get("/marking/{$this->exam->id}")->assertNotFound();
});

test('an appointed examiner sees the examination even without a teaching assignment', function () {
    $assigner = $this->staffUser([$this->assignerRole], $this->branch);
    $examiner = $this->staffUser([$this->markerRole], $this->branch);

    $this->actingAs($assigner, 'web')->post("/marking/{$this->exam->id}/examiners", ['user_id' => $examiner->id, 'role' => 'first'])
        ->assertSessionHasNoErrors();

    $this->actingAs($examiner, 'web')->get('/marking')
        ->assertInertia(fn ($page) => $page->has('examinations', 1)->where('examinations.0.id', $this->exam->id));

    $this->actingAs($examiner, 'web')->get("/marking/{$this->exam->id}")->assertOk();
});

test('whoever appoints examiners sees the whole campus, so an empty Assign Program Teacher locks nobody out', function () {
    $assigner = $this->staffUser([$this->assignerRole], $this->branch);

    $this->actingAs($assigner, 'web')->get('/marking')
        ->assertInertia(fn ($page) => $page->has('examinations', 1)
            ->where('scopedByTeaching', false));
});

test('the marking list names the programme, intake and course the examination belongs to', function () {
    $assigner = $this->staffUser([$this->assignerRole], $this->branch);

    $this->actingAs($assigner, 'web')->get('/marking')
        ->assertInertia(fn ($page) => $page->where('examinations.0.programme', fn (?string $name): bool => $name !== null && $name !== '')
            ->where('examinations.0.course', fn (?string $label): bool => $label !== null && str_contains($label, '—')));
});

test('marking cannot be opened at all without one of its three permissions', function () {
    $noneRole = $this->cmsRole('No marking rights');
    $this->cmsGrant($noneRole, 'exam_results', 'view');
    $outsider = $this->staffUser([$noneRole], $this->branch);

    $this->actingAs($outsider, 'web')->get('/marking')->assertForbidden();
    $this->actingAs($outsider, 'web')->get("/marking/{$this->exam->id}")->assertForbidden();
});

test('an examiner whose exam access forbids the course is not offered for appointment', function () {
    $allowed = $this->staffUser([$this->markerRole], $this->branch);
    $forbidden = $this->staffUser([$this->markerRole], $this->branch);

    // Limited to a different programme, so this examination is out of their reach.
    $this->cmsExamScope($forbidden, 'programme', $this->cmsProgramme($this->branch, 'DPT'));

    $offered = collect(app(ExaminerCandidates::class)->forExamination($this->exam))->pluck('id');

    expect($offered)->toContain($allowed->id)
        ->and($offered)->not->toContain($forbidden->id);
});

test('somebody who teaches nothing can still be appointed, the way an external examiner is', function () {
    $assigner = $this->staffUser([$this->assignerRole], $this->branch);
    $external = $this->staffUser([$this->markerRole], $this->branch);

    expect(collect(app(ExaminerCandidates::class)->forExamination($this->exam))->pluck('id'))
        ->toContain($external->id);

    $this->actingAs($assigner, 'web')->post("/marking/{$this->exam->id}/examiners", ['user_id' => $external->id, 'role' => 'first'])
        ->assertSessionHasNoErrors();
});
