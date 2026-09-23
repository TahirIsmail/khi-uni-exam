<?php

use App\Domain\Candidate\Actions\CheckInCandidate;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Candidate\Models\Centre;
use App\Domain\Candidate\Models\Room;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\QuestionBank\Models\Question;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsExaminations;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class, BuildsExaminations::class);

beforeEach(function () {
    $this->examWorld();

    $this->exam = $this->approvedExam(
        ['rows' => [
            ['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId('single_best_answer'), 'question_count' => 1, 'marks_each' => 4],
            ['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId('essay'), 'question_count' => 1, 'marks_each' => 6],
        ]],
        ['total_marks' => 10],
    );

    $sba = $this->activeQuestion($this->node, $this->typeId('single_best_answer'), null, false, null, null, null, null, null, null, null, [
        ['A', 'Wrong', false],
        ['B', 'Right', true],
        ['C', 'Also wrong', false],
    ]);
    $this->correctOptionId = (int) DB::table('qb_question_options')->where('version_id', $sba->id)->where('is_correct', true)->value('id');
    $this->wrongOptionId = (int) DB::table('qb_question_options')->where('version_id', $sba->id)->where('label', 'A')->value('id');
    $this->sbaQuestionId = $sba->question_id;

    $this->activeQuestion($this->node, $this->typeId('essay'));

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

    $this->assignRole = $this->cmsRole('Marking controller');
    $this->cmsGrant($this->assignRole, 'exam_marking_assign', 'view');
    $this->assigner = $this->staffUser([$this->assignRole], $this->branch);

    $this->analystRole = $this->cmsRole('Analyst');
    $this->cmsGrant($this->analystRole, 'exam_item_analysis', 'view', 'add', 'edit');
    $this->analyst = $this->staffUser([$this->analystRole], $this->branch);

    $this->actingAs($this->conductOfficer, 'web')->post('/exams/conduct/centres', ['name' => 'Hall', 'code' => 'H-'.Str::random(6)])->assertSessionHasNoErrors();
    $this->centre = Centre::query()->latest('id')->firstOrFail();
    $this->actingAs($this->conductOfficer, 'web')->post("/exams/conduct/centres/{$this->centre->id}/rooms", ['name' => 'R1', 'capacity' => 10])->assertSessionHasNoErrors();
    $this->room = Room::query()->latest('id')->firstOrFail();

    $this->actingAs($this->conductOfficer, 'web')->post("/exams/{$this->exam->id}/candidates/import", [
        'file' => UploadedFile::fake()->createWithContent('c.csv', "candidate_no,name\nC-001,One\nC-002,Two\nC-003,Three"),
    ])->assertSessionHasNoErrors();

    $this->pins = [];
    foreach (['C-001', 'C-002', 'C-003'] as $no) {
        $candidate = Candidate::query()->where('candidate_no', $no)->firstOrFail();
        $this->actingAs($this->conductOfficer, 'web')->post("/exams/{$this->exam->id}/candidates/{$candidate->id}/allocate", ['room_id' => $this->room->id])->assertSessionHasNoErrors();
        $checkedIn = app(CheckInCandidate::class)($this->conductOfficer, $this->exam, $candidate->refresh());
        $this->pins[$no] = $checkedIn['pin'];
    }

    $this->actingAs($this->assigner, 'web')->post("/marking/{$this->exam->id}/examiners", ['user_id' => $this->examiner1->id, 'role' => 'first'])->assertSessionHasNoErrors();
});

/** Signs one candidate in, answers the SBA item correctly or not, and submits. */
function analyticsSitAndSubmit(string $candidateNo, bool $answerCorrectly): array
{
    $t = test();
    $t->post('/sit/'.$t->exam->id, ['candidate_no' => $candidateNo, 'pin' => $t->pins[$candidateNo]]);
    $candidate = Candidate::query()->where('candidate_no', $candidateNo)->firstOrFail();
    $attempt = CandidateExam::query()->where('candidate_id', $candidate->id)->firstOrFail();
    $items = $attempt->items()->with('paperItem')->get();

    $sbaItem = $items->firstWhere('paperItem.question_type_id', $t->typeId('single_best_answer'));
    $essayItem = $items->firstWhere('paperItem.question_type_id', $t->typeId('essay'));

    $chosen = $answerCorrectly ? $t->correctOptionId : $t->wrongOptionId;
    $t->post("/sit/{$t->exam->id}/answer", ['item_id' => $sbaItem->id, 'sequence' => 1, 'payload' => ['selected' => [$chosen]]]);
    $t->post("/sit/{$t->exam->id}/answer", ['item_id' => $essayItem->id, 'sequence' => 2, 'payload' => ['text' => 'An essay answer.']]);
    $t->post("/sit/{$t->exam->id}/submit");
    $t->post("/sit/{$t->exam->id}/logout");

    return ['attempt' => $attempt->fresh(), 'essayItem' => $essayItem];
}

function markAllEssays(array $essayItemIds, float $marks = 6.0): void
{
    $t = test();
    foreach ($essayItemIds as $id) {
        $t->actingAs($t->examiner1, 'web')->post("/marking/{$t->exam->id}/items/{$id}/mark", [
            'marks_awarded' => $marks,
            'criteria' => [],
        ])->assertSessionHasNoErrors();
    }
}

test('analysis refuses to run while any attempt is still pending marking', function () {
    analyticsSitAndSubmit('C-001', true);

    $this->actingAs($this->analyst, 'web')->post("/results/{$this->exam->id}/analysis/run")->assertInvalid(['analysis']);
});

test('item analysis reports difficulty from the final mark of every attempt', function () {
    $essayItems = [];
    $essayItems[] = analyticsSitAndSubmit('C-001', true)['essayItem']->id;
    $essayItems[] = analyticsSitAndSubmit('C-002', false)['essayItem']->id;
    $essayItems[] = analyticsSitAndSubmit('C-003', true)['essayItem']->id;
    markAllEssays($essayItems);

    $this->actingAs($this->analyst, 'web')->post("/results/{$this->exam->id}/analysis/run")->assertSessionHasNoErrors();

    $usage = DB::table('qb_question_usage')->where('question_id', $this->sbaQuestionId)->where('exam_id', $this->exam->id)->first();
    expect($usage->candidates)->toBe(3)
        ->and($usage->correct_count)->toBe(2)
        ->and((float) $usage->observed_p)->toEqualWithDelta(2 / 3, 0.001);
});

test('discrimination and reliability are unavailable below the candidate threshold, and available above it', function () {
    $essayItems = [];
    $essayItems[] = analyticsSitAndSubmit('C-001', true)['essayItem']->id;
    $essayItems[] = analyticsSitAndSubmit('C-002', false)['essayItem']->id;
    $essayItems[] = analyticsSitAndSubmit('C-003', true)['essayItem']->id;
    markAllEssays($essayItems);

    // Default threshold (10): three candidates is not enough.
    $this->actingAs($this->analyst, 'web')->post("/results/{$this->exam->id}/analysis/run")->assertSessionHasNoErrors();
    $usage = DB::table('qb_question_usage')->where('question_id', $this->sbaQuestionId)->where('exam_id', $this->exam->id)->first();
    expect($usage->discrimination)->toBeNull();

    config(['exam.analytics.min_candidates' => 2]);
    $this->actingAs($this->analyst, 'web')->post("/results/{$this->exam->id}/analysis/run")->assertSessionHasNoErrors();
    $usage = DB::table('qb_question_usage')->where('question_id', $this->sbaQuestionId)->where('exam_id', $this->exam->id)->first();
    expect($usage->discrimination)->not->toBeNull();
});

test('a discard decision archives the question, and retain does not', function () {
    $essayItems = [];
    $essayItems[] = analyticsSitAndSubmit('C-001', true)['essayItem']->id;
    $essayItems[] = analyticsSitAndSubmit('C-002', false)['essayItem']->id;
    $essayItems[] = analyticsSitAndSubmit('C-003', true)['essayItem']->id;
    markAllEssays($essayItems);
    $this->actingAs($this->analyst, 'web')->post("/results/{$this->exam->id}/analysis/run");

    $sbaVersionId = DB::table('qb_questions')->where('id', $this->sbaQuestionId)->value('active_version_id');

    $this->actingAs($this->analyst, 'web')->post("/results/{$this->exam->id}/questions/{$sbaVersionId}/decision", [
        'decision' => 'remove',
        'reason' => 'This item performed poorly and should not be reused.',
    ])->assertSessionHasNoErrors();

    expect(Question::query()->find($this->sbaQuestionId)->is_archived)->toBeTrue();
});

test('a retain decision does not archive the question', function () {
    $essayItems = [];
    $essayItems[] = analyticsSitAndSubmit('C-001', true)['essayItem']->id;
    markAllEssays($essayItems);
    $this->actingAs($this->analyst, 'web')->post("/results/{$this->exam->id}/analysis/run");

    $essayVersionId = DB::table('qb_questions')->join('qb_question_versions', 'qb_question_versions.question_id', '=', 'qb_questions.id')
        ->where('qb_question_versions.question_type_id', $this->typeId('essay'))->value('qb_questions.active_version_id');

    // An essay can be discarded post-hoc too, once it has usage statistics (unlike a mid-exam re-key).
    $this->actingAs($this->analyst, 'web')->post("/results/{$this->exam->id}/questions/{$essayVersionId}/decision", [
        'decision' => 'retain',
        'reason' => 'Performed well; keep it as it is.',
    ])->assertSessionHasNoErrors();

    $question = DB::table('qb_questions')->where('active_version_id', $essayVersionId)->first();
    expect((bool) $question->is_archived)->toBeFalse();
});

test('deciding about a question before analysis has run is refused', function () {
    analyticsSitAndSubmit('C-001', true);
    $sbaVersionId = DB::table('qb_questions')->where('id', $this->sbaQuestionId)->value('active_version_id');

    $this->actingAs($this->analyst, 'web')->post("/results/{$this->exam->id}/questions/{$sbaVersionId}/decision", [
        'decision' => 'remove',
        'reason' => 'Trying before analysis has run.',
    ])->assertInvalid(['analysis']);
});

test('a post-hoc decision is never changed or deleted', function () {
    $essayItems = [analyticsSitAndSubmit('C-001', true)['essayItem']->id];
    markAllEssays($essayItems);
    $this->actingAs($this->analyst, 'web')->post("/results/{$this->exam->id}/analysis/run");

    $sbaVersionId = DB::table('qb_questions')->where('id', $this->sbaQuestionId)->value('active_version_id');
    $this->actingAs($this->analyst, 'web')->post("/results/{$this->exam->id}/questions/{$sbaVersionId}/decision", [
        'decision' => 'retain',
        'reason' => 'Fine as it is.',
    ]);
    $decisionId = DB::table('qb_posthoc_decisions')->where('version_id', $sbaVersionId)->value('id');

    expect(fn () => DB::table('qb_posthoc_decisions')->where('id', $decisionId)->update(['reason' => 'changed']))
        ->toThrow(QueryException::class, 'never changed')
        ->and(fn () => DB::table('qb_posthoc_decisions')->where('id', $decisionId)->delete())
        ->toThrow(QueryException::class, 'never deleted');
});

test('run and decide each need their own right, separate from analytics.view', function () {
    $essayItems = [analyticsSitAndSubmit('C-001', true)['essayItem']->id];
    markAllEssays($essayItems);
    $sbaVersionId = DB::table('qb_questions')->where('id', $this->sbaQuestionId)->value('active_version_id');

    $viewOnlyRole = $this->cmsRole('Analysis viewer');
    $this->cmsGrant($viewOnlyRole, 'exam_item_analysis', 'view');
    $viewer = $this->staffUser([$viewOnlyRole], $this->branch);

    $this->actingAs($viewer, 'web')->get("/results/{$this->exam->id}/analysis")->assertOk();
    $this->actingAs($viewer, 'web')->post("/results/{$this->exam->id}/analysis/run")->assertForbidden();
    $this->actingAs($viewer, 'web')->post("/results/{$this->exam->id}/questions/{$sbaVersionId}/decision", [
        'decision' => 'retain',
        'reason' => 'Attempted without the right.',
    ])->assertForbidden();
});
