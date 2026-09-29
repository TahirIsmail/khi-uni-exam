<?php

use App\Domain\Candidate\Actions\CheckInCandidate;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Candidate\Models\Centre;
use App\Domain\Candidate\Models\Room;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Reports\Queries\CohortKey;
use App\Domain\Reports\Queries\TabulationSheet;
use App\Domain\Results\Models\ResultComponent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsExaminations;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class, BuildsExaminations::class);

/*
 * The parts of a professional result this system does not run.
 *
 * The paper here is out of 60 and everybody who answers it correctly gets all 60. On top of that
 * sit a 30-mark OSPE and a 10-mark internal assessment, so the subject is out of 100 — which keeps
 * the arithmetic below readable as percentages.
 *
 * Ayesha answers the paper right; Bilal answers it wrong. What they are given for the OSPE is what
 * each test decides, because the separate pass rule is the point of the whole exercise.
 */
beforeEach(function () {
    $this->examWorld();

    $this->exam = $this->approvedExam(
        ['rows' => [['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId('single_best_answer'), 'question_count' => 1, 'marks_each' => 60]]],
        ['total_marks' => 60, 'pass_percentage' => 50],
    );

    $sba = $this->activeQuestion($this->node, $this->typeId('single_best_answer'), null, false, null, null, null, null, null, null, null, [
        ['A', 'Wrong', false],
        ['B', 'Right', true],
    ]);
    $this->correctOptionId = (int) DB::table('qb_question_options')->where('version_id', $sba->id)->where('is_correct', true)->value('id');
    $this->wrongOptionId = (int) DB::table('qb_question_options')->where('version_id', $sba->id)->where('label', 'A')->value('id');

    $paperUrl = "/exams/{$this->exam->id}/paper";
    $this->actingAs($this->setter, 'web')->post($paperUrl)->assertSessionHasNoErrors();
    $this->actingAs($this->setter, 'web')->post("{$paperUrl}/fill", ['mode' => 'gaps'])->assertSessionHasNoErrors();
    $this->actingAs($this->setter, 'web')->post("{$paperUrl}/submit")->assertSessionHasNoErrors();
    $this->actingAs($this->approver, 'web')->post("{$paperUrl}/approve")->assertSessionHasNoErrors();
    $this->actingAs($this->approver, 'web')->post("{$paperUrl}/finalise")->assertSessionHasNoErrors();

    $publisherRole = $this->cmsRole('Paper publisher');
    $this->cmsGrant($publisherRole, 'exam_papers', 'view');
    $this->cmsGrant($publisherRole, 'exam_papers_publish', 'view');
    $this->actingAs($this->staffUser([$publisherRole], $this->branch), 'web')->post("{$paperUrl}/publish")->assertSessionHasNoErrors();

    $conductRole = $this->cmsRole('Conduct officer');
    $this->cmsGrant($conductRole, 'exam_candidates', 'view', 'edit');
    $this->cmsGrant($conductRole, 'exam_centres', 'view', 'edit');
    $this->cmsGrant($conductRole, 'exam_allocation', 'view');
    $this->cmsGrant($conductRole, 'exam_checkin', 'view');
    $this->officer = $this->staffUser([$conductRole], $this->branch);

    $this->actingAs($this->officer, 'web')->post('/exams/conduct/centres', ['name' => 'Hall', 'code' => 'H-'.Str::random(6)])->assertSessionHasNoErrors();
    $centre = Centre::query()->latest('id')->firstOrFail();
    $this->actingAs($this->officer, 'web')->post("/exams/conduct/centres/{$centre->id}/rooms", ['name' => 'R1', 'capacity' => 10])->assertSessionHasNoErrors();
    $this->room = Room::query()->latest('id')->firstOrFail();

    $this->actingAs($this->officer, 'web')->post("/exams/{$this->exam->id}/candidates/import", [
        'file' => UploadedFile::fake()->createWithContent('c.csv', "candidate_no,name\nC-001,Ayesha Khan\nC-002,Bilal Ahmed"),
    ])->assertSessionHasNoErrors();

    componentSit('C-001', $this->correctOptionId);
    componentSit('C-002', $this->wrongOptionId);

    $controllerRole = $this->cmsRole('Results controller');
    $this->cmsGrant($controllerRole, 'exam_results', 'view');
    $this->cmsGrant($controllerRole, 'exam_results_approve', 'view');
    $this->cmsGrant($controllerRole, 'exam_results_publish', 'view');
    $this->cmsGrant($controllerRole, 'exam_result_components', 'view');
    $this->cmsGrant($controllerRole, 'exam_reports', 'view');
    $this->controller = $this->staffUser([$controllerRole], $this->branch);

    // Somebody who may read results but has no business entering a practical mark.
    $readerRole = $this->cmsRole('Results reader');
    $this->cmsGrant($readerRole, 'exam_results', 'view');
    $this->reader = $this->staffUser([$readerRole], $this->branch);

    $this->cohort = new CohortKey(
        $this->branch, (int) $this->exam->programme_id, (int) $this->exam->professional_id, null,
    );
});

function componentSit(string $candidateNo, int $optionId): void
{
    $t = test();
    $candidate = Candidate::query()->where('candidate_no', $candidateNo)->firstOrFail();
    $t->actingAs($t->officer, 'web')->post("/exams/{$t->exam->id}/candidates/{$candidate->id}/allocate", ['room_id' => $t->room->id])->assertSessionHasNoErrors();

    $checkedIn = app(CheckInCandidate::class)($t->officer, $t->exam, $candidate->refresh());

    $t->post('/sit/'.$t->exam->id, ['candidate_no' => $candidateNo, 'pin' => $checkedIn['pin']]);
    $attempt = CandidateExam::query()->where('candidate_id', $candidate->id)->firstOrFail();
    $item = $attempt->items()->firstOrFail();
    $t->post("/sit/{$t->exam->id}/answer", ['item_id' => $item->id, 'sequence' => 1, 'payload' => ['selected' => [$optionId]]]);
    $t->post("/sit/{$t->exam->id}/submit");
}

/** A 30-mark OSPE that must be passed on its own, and a 10-mark internal assessment. */
function defineComponents(): void
{
    test()->actingAs(test()->controller, 'web')
        ->post('/results/'.test()->exam->id.'/components/define', [
            'components' => [
                ['code' => 'ospe', 'name' => 'Practical / OSPE', 'max_marks' => 30, 'group' => 'practical', 'min_pass_percentage' => 50],
                ['code' => 'internal', 'name' => 'Internal assessment', 'max_marks' => 10, 'group' => 'theory', 'min_pass_percentage' => null],
            ],
        ])->assertSessionHasNoErrors();
}

/** @param  array<string, float>  $byCandidateNo */
function enterComponent(string $code, array $byCandidateNo): void
{
    $t = test();
    $component = ResultComponent::query()->where('examination_id', $t->exam->id)->where('code', $code)->firstOrFail();

    $marks = [];
    foreach ($byCandidateNo as $candidateNo => $value) {
        $marks[Candidate::query()->where('candidate_no', $candidateNo)->value('id')] = $value;
    }

    $t->actingAs($t->controller, 'web')
        ->post("/results/{$t->exam->id}/components/marks", ['component_id' => $component->id, 'marks' => $marks])
        ->assertSessionHasNoErrors();
}

function publishComponentResults(): void
{
    $t = test();
    $t->actingAs($t->controller, 'web')->post("/results/{$t->exam->id}/approve")->assertSessionHasNoErrors();
    $t->actingAs($t->controller, 'web')->post("/results/{$t->exam->id}/publish")->assertSessionHasNoErrors();
}

test('defining components adds the theory paper for you, worth the examination\'s own marks', function () {
    defineComponents();

    $components = ResultComponent::query()->where('examination_id', $this->exam->id)->orderBy('sort_order')->get();

    expect($components)->toHaveCount(3)
        ->and($components[0]->code)->toBe('theory')
        ->and($components[0]->source)->toBe('cbt')
        ->and($components[0]->max_marks)->toBe(60.0)
        // PMC's rule: the paper is passed on its own, at the examination's own pass mark.
        ->and($components[0]->min_pass_percentage)->toBe(50.0)
        ->and($components[1]->code)->toBe('ospe')
        ->and($components[2]->code)->toBe('internal');
});

test('the subject is out of every component together, and totals across them', function () {
    defineComponents();
    enterComponent('ospe', ['C-001' => 24, 'C-002' => 27]);
    enterComponent('internal', ['C-001' => 8, 'C-002' => 9]);
    publishComponentResults();

    $sheet = app(TabulationSheet::class)->for($this->cohort);

    expect($sheet['courses'][0]['totalMarks'])->toBe(100.0);

    $ayesha = collect($sheet['candidates'])->firstWhere('candidateNo', 'C-001');

    // 60 on paper + 24 OSPE + 8 internal = 92.
    expect($ayesha['obtainedMarks'])->toBe(92.0)
        ->and($ayesha['possibleMarks'])->toBe(100.0)
        ->and($ayesha['percentage'])->toBe(92.0)
        ->and($ayesha['isPass'])->toBeTrue();
});

test('failing the practical fails the subject, however good the total is', function () {
    defineComponents();
    // Ayesha is 60/60 on paper but 12/30 in the OSPE — 40%, under the 50% the practical asks for.
    // Her total is 80 of 100, which on its own would be a comfortable pass.
    enterComponent('ospe', ['C-001' => 12]);
    enterComponent('internal', ['C-001' => 8]);
    publishComponentResults();

    $sheet = app(TabulationSheet::class)->for($this->cohort);
    $ayesha = collect($sheet['candidates'])->firstWhere('candidateNo', 'C-001');

    expect($ayesha['percentage'])->toBe(80.0)
        ->and($ayesha['isPass'])->toBeFalse()
        ->and($ayesha['courses'][$this->exam->id]['failedGroups'])->toBe(['practical']);
});

test('failing the theory paper fails the subject even where the practical carries the total', function () {
    defineComponents();
    // Bilal got nothing on the paper. A perfect OSPE and internal give him 40 of 100 — he fails on
    // the total as well, but the point is that the theory bar is checked in its own right.
    enterComponent('ospe', ['C-002' => 30]);
    enterComponent('internal', ['C-002' => 10]);
    publishComponentResults();

    $sheet = app(TabulationSheet::class)->for($this->cohort);
    $bilal = collect($sheet['candidates'])->firstWhere('candidateNo', 'C-002');

    expect($bilal['isPass'])->toBeFalse()
        ->and($bilal['courses'][$this->exam->id]['failedGroups'])->toContain('theory');
});

test('a component nobody has entered leaves the subject incomplete rather than scoring it nil', function () {
    defineComponents();
    enterComponent('ospe', ['C-001' => 24]);
    // The internal assessment is not entered for anybody.
    publishComponentResults();

    $sheet = app(TabulationSheet::class)->for($this->cohort);
    $ayesha = collect($sheet['candidates'])->firstWhere('candidateNo', 'C-001');

    expect($ayesha['courses'][$this->exam->id]['pending'])->toBeTrue()
        ->and($ayesha['courses'][$this->exam->id]['totalMarks'])->toBeNull()
        ->and($ayesha['satEverything'])->toBeFalse()
        ->and($ayesha['isPass'])->toBeFalse();
});

test('an examination with no components produces exactly the numbers it always did', function () {
    publishComponentResults();

    $sheet = app(TabulationSheet::class)->for($this->cohort);
    $ayesha = collect($sheet['candidates'])->firstWhere('candidateNo', 'C-001');

    expect($sheet['courses'][0]['totalMarks'])->toBe(60.0)
        ->and($sheet['courses'][0]['components'])->toBe([])
        ->and($ayesha['obtainedMarks'])->toBe(60.0)
        ->and($ayesha['percentage'])->toBe(100.0)
        ->and($ayesha['isPass'])->toBeTrue();
});

test('a mark above what the component is worth is refused', function () {
    defineComponents();
    $component = ResultComponent::query()->where('examination_id', $this->exam->id)->where('code', 'ospe')->firstOrFail();
    $candidateId = Candidate::query()->where('candidate_no', 'C-001')->value('id');

    $this->actingAs($this->controller, 'web')
        ->post("/results/{$this->exam->id}/components/marks", ['component_id' => $component->id, 'marks' => [$candidateId => 31]])
        ->assertSessionHasErrors('marks');

    expect(DB::table('exm_component_marks')->count())->toBe(0);
});

test('the computer-based paper is never typed in', function () {
    defineComponents();
    $theory = ResultComponent::query()->where('examination_id', $this->exam->id)->where('code', 'theory')->firstOrFail();
    $candidateId = Candidate::query()->where('candidate_no', 'C-001')->value('id');

    $this->actingAs($this->controller, 'web')
        ->post("/results/{$this->exam->id}/components/marks", ['component_id' => $theory->id, 'marks' => [$candidateId => 10]])
        ->assertSessionHasErrors('component_id');
});

test('entering a component mark after publication is refused', function () {
    defineComponents();
    enterComponent('ospe', ['C-001' => 24, 'C-002' => 20]);
    enterComponent('internal', ['C-001' => 8, 'C-002' => 8]);
    publishComponentResults();

    $component = ResultComponent::query()->where('examination_id', $this->exam->id)->where('code', 'ospe')->firstOrFail();
    $candidateId = Candidate::query()->where('candidate_no', 'C-001')->value('id');

    $this->actingAs($this->controller, 'web')
        ->post("/results/{$this->exam->id}/components/marks", ['component_id' => $component->id, 'marks' => [$candidateId => 30]])
        ->assertSessionHasErrors('marks');
});

test('entering a component mark is written into the audit chain', function () {
    defineComponents();
    enterComponent('ospe', ['C-001' => 24]);

    expect(DB::table('sec_audit_logs')->where('action', 'result.component_mark_entered')->count())->toBe(1);

    // Correcting it is recorded as a correction, with what it was before.
    enterComponent('ospe', ['C-001' => 26]);

    $correction = DB::table('sec_audit_logs')->where('action', 'result.component_mark_corrected')->first();

    expect($correction)->not->toBeNull()
        ->and($correction->old_values)->toContain('24');
});

test('somebody who may read results still may not enter a practical mark', function () {
    defineComponents();

    $this->actingAs($this->reader, 'web')
        ->get("/results/{$this->exam->id}/components")
        ->assertForbidden();
});

test('what the result is made of cannot change once marks have been entered against it', function () {
    defineComponents();
    enterComponent('ospe', ['C-001' => 24]);

    $this->actingAs($this->controller, 'web')
        ->post("/results/{$this->exam->id}/components/define", [
            'components' => [
                ['code' => 'ospe', 'name' => 'Practical / OSPE', 'max_marks' => 40, 'group' => 'practical', 'min_pass_percentage' => 50],
            ],
        ])->assertSessionHasErrors('components');
});
