<?php

use App\Domain\Candidate\Actions\CheckInCandidate;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Candidate\Models\Centre;
use App\Domain\Candidate\Models\Room;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Results\Models\ItemRekey;
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
    $this->wrongOptionId = (int) DB::table('qb_question_options')->where('version_id', $sba->id)->where('label', 'A')->value('id');
    $this->correctOptionId = (int) DB::table('qb_question_options')->where('version_id', $sba->id)->where('is_correct', true)->value('id');

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

    $this->resultsRole = $this->cmsRole('Results controller');
    $this->cmsGrant($this->resultsRole, 'exam_results', 'view');
    $this->cmsGrant($this->resultsRole, 'exam_results_approve', 'view');
    $this->cmsGrant($this->resultsRole, 'exam_results_publish', 'view');
    $this->cmsGrant($this->resultsRole, 'exam_results_rescore', 'view');
    $this->controller = $this->staffUser([$this->resultsRole], $this->branch);

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

    $this->actingAs($this->assigner, 'web')->post("/marking/{$this->exam->id}/examiners", ['user_id' => $this->examiner1->id, 'role' => 'first'])->assertSessionHasNoErrors();
});

/** Signs the one candidate in, answers the SBA item wrong, and submits. Essay is left unmarked. */
function resultsSitAndSubmitWrong(): array
{
    $t = test();
    $t->post('/sit/'.$t->exam->id, ['candidate_no' => 'C-001', 'pin' => $t->pin]);
    $attempt = CandidateExam::query()->where('candidate_id', $t->candidate->id)->firstOrFail();
    $items = $attempt->items()->with('paperItem')->get();

    $sbaItem = $items->firstWhere('paperItem.question_type_id', $t->typeId('single_best_answer'));
    $essayItem = $items->firstWhere('paperItem.question_type_id', $t->typeId('essay'));

    $t->post("/sit/{$t->exam->id}/answer", ['item_id' => $sbaItem->id, 'sequence' => 1, 'payload' => ['selected' => [$t->wrongOptionId]]]);
    $t->post("/sit/{$t->exam->id}/answer", ['item_id' => $essayItem->id, 'sequence' => 2, 'payload' => ['text' => 'An essay answer.']]);
    $t->post("/sit/{$t->exam->id}/submit");

    return ['attempt' => $attempt->fresh(), 'sbaItem' => $sbaItem, 'essayItem' => $essayItem];
}

function markEssay(object $essayItem, float $marks = 6.0): void
{
    $t = test();
    $t->actingAs($t->examiner1, 'web')->post("/marking/{$t->exam->id}/items/{$essayItem->id}/mark", [
        'marks_awarded' => $marks,
        'criteria' => [],
    ])->assertSessionHasNoErrors();
}

test('results cannot be approved while an item is still pending marking', function () {
    resultsSitAndSubmitWrong();

    $this->actingAs($this->controller, 'web')->post("/results/{$this->exam->id}/approve")->assertInvalid(['results']);
});

test('negative marking deducts only for a wrong, answered, auto-marked item', function () {
    DB::table('exm_examinations')->where('id', $this->exam->id)->update(['negative_marking' => true, 'negative_fraction' => 0.25]);
    $built = resultsSitAndSubmitWrong();
    markEssay($built['essayItem']);

    $this->actingAs($this->controller, 'web')->post("/results/{$this->exam->id}/approve")->assertSessionHasNoErrors();

    $result = DB::table('exm_results')->where('candidate_exam_id', $built['attempt']->id)->first();
    // SBA wrong (0/4, answered → 0.25 * 4 = 1 deducted), essay marked 6/6, raw = 0 + 6 = 6, total = 6 - 1 = 5.
    expect((float) $result->raw_marks)->toBe(6.0)
        ->and((float) $result->negative_deduction)->toBe(1.0)
        ->and((float) $result->total_marks)->toBe(5.0);
});

test('a compiled result carries the grade its programme awards, and an annual one carries no grade point', function () {
    $built = resultsSitAndSubmitWrong();
    markEssay($built['essayItem']);

    $this->actingAs($this->controller, 'web')->post("/results/{$this->exam->id}/approve")->assertSessionHasNoErrors();

    // BuildsExaminations makes annual programmes, which are graded out of marks and have no points.
    $result = DB::table('exm_results')->where('candidate_exam_id', $built['attempt']->id)->first();
    expect($result->grade)->not->toBeNull()
        ->and($result->grade_remark)->not->toBeNull()
        ->and($result->grade_point)->toBeNull();
});

test('approval needs every attempt clear, and publishing needs approval first', function () {
    $built = resultsSitAndSubmitWrong();
    markEssay($built['essayItem']);

    $this->actingAs($this->controller, 'web')->post("/results/{$this->exam->id}/publish")->assertInvalid(['results']);

    $this->actingAs($this->controller, 'web')->post("/results/{$this->exam->id}/approve")->assertSessionHasNoErrors();
    expect(DB::table('exm_result_publications')->where('examination_id', $this->exam->id)->value('status'))->toBe('approved');

    $this->actingAs($this->controller, 'web')->post("/results/{$this->exam->id}/publish")->assertSessionHasNoErrors();
    expect(DB::table('exm_result_publications')->where('examination_id', $this->exam->id)->value('status'))->toBe('published');
});

test('re-keying a discarded item gives everyone full marks and resets publication to draft', function () {
    $built = resultsSitAndSubmitWrong();
    markEssay($built['essayItem']);
    $this->actingAs($this->controller, 'web')->post("/results/{$this->exam->id}/approve");
    $this->actingAs($this->controller, 'web')->post("/results/{$this->exam->id}/publish");

    $paperItemId = DB::table('cand_paper_items')->where('id', $built['sbaItem']->id)->value('paper_item_id');

    $this->actingAs($this->controller, 'web')->post("/results/{$this->exam->id}/items/{$paperItemId}/rekey", [
        'decision' => 'discard',
        'reason' => 'This item had two defensible answers.',
    ])->assertSessionHasNoErrors();

    expect((float) DB::table('mrk_item_marks')->where('cand_paper_item_id', $built['sbaItem']->id)->where('source', 'rekeyed')->value('marks_awarded'))->toBe(4.0)
        ->and(DB::table('exm_result_publications')->where('examination_id', $this->exam->id)->value('status'))->toBe('draft');
});

test('re-keying the correct option rescores the candidate who chose differently', function () {
    $built = resultsSitAndSubmitWrong();
    markEssay($built['essayItem']);

    $paperItemId = DB::table('cand_paper_items')->where('id', $built['sbaItem']->id)->value('paper_item_id');

    $this->actingAs($this->controller, 'web')->post("/results/{$this->exam->id}/items/{$paperItemId}/rekey", [
        'decision' => 'correct_option',
        'corrected_option_id' => $this->wrongOptionId,
        'reason' => 'Option A is actually correct; the key was wrong.',
    ])->assertSessionHasNoErrors();

    expect((float) DB::table('mrk_item_marks')->where('cand_paper_item_id', $built['sbaItem']->id)->where('source', 'rekeyed')->value('marks_awarded'))->toBe(4.0)
        ->and(DB::table('sec_audit_logs')->where('action', 'result.rekeyed')->count())->toBe(1);
});

test('re-keying the same item twice is refused', function () {
    $built = resultsSitAndSubmitWrong();
    $paperItemId = DB::table('cand_paper_items')->where('id', $built['sbaItem']->id)->value('paper_item_id');

    $this->actingAs($this->controller, 'web')->post("/results/{$this->exam->id}/items/{$paperItemId}/rekey", [
        'decision' => 'discard',
        'reason' => 'First correction.',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->controller, 'web')->post("/results/{$this->exam->id}/items/{$paperItemId}/rekey", [
        'decision' => 'discard',
        'reason' => 'Second correction.',
    ])->assertInvalid(['item']);
});

test('an essay cannot be re-keyed: mark it again instead', function () {
    $built = resultsSitAndSubmitWrong();
    $essayPaperItemId = DB::table('cand_paper_items')->where('id', $built['essayItem']->id)->value('paper_item_id');

    $this->actingAs($this->controller, 'web')->post("/results/{$this->exam->id}/items/{$essayPaperItemId}/rekey", [
        'decision' => 'discard',
        'reason' => 'Trying to re-key an essay.',
    ])->assertInvalid(['decision']);
});

test('a re-key is never changed or deleted', function () {
    $built = resultsSitAndSubmitWrong();
    $paperItemId = DB::table('cand_paper_items')->where('id', $built['sbaItem']->id)->value('paper_item_id');

    $this->actingAs($this->controller, 'web')->post("/results/{$this->exam->id}/items/{$paperItemId}/rekey", [
        'decision' => 'discard',
        'reason' => 'A correction.',
    ]);
    $rekeyId = ItemRekey::query()->where('paper_item_id', $paperItemId)->value('id');

    expect(fn () => DB::table('exm_item_rekeys')->where('id', $rekeyId)->update(['reason' => 'changed']))
        ->toThrow(QueryException::class, 'never changed')
        ->and(fn () => DB::table('exm_item_rekeys')->where('id', $rekeyId)->delete())
        ->toThrow(QueryException::class, 'never deleted');
});

test('approve, publish and rescore each need their own right, separate from result.view', function () {
    $built = resultsSitAndSubmitWrong();
    markEssay($built['essayItem']);
    $paperItemId = DB::table('cand_paper_items')->where('id', $built['sbaItem']->id)->value('paper_item_id');

    $viewOnlyRole = $this->cmsRole('Results viewer');
    $this->cmsGrant($viewOnlyRole, 'exam_results', 'view');
    $viewer = $this->staffUser([$viewOnlyRole], $this->branch);

    $this->actingAs($viewer, 'web')->get("/results/{$this->exam->id}")->assertOk();
    $this->actingAs($viewer, 'web')->post("/results/{$this->exam->id}/approve")->assertForbidden();
    $this->actingAs($viewer, 'web')->post("/results/{$this->exam->id}/publish")->assertForbidden();
    $this->actingAs($viewer, 'web')->post("/results/{$this->exam->id}/items/{$paperItemId}/rekey", [
        'decision' => 'discard',
        'reason' => 'Attempted without the right.',
    ])->assertForbidden();
});
