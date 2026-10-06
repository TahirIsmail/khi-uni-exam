<?php

use App\Domain\Candidate\Actions\CheckInCandidate;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Candidate\Models\Centre;
use App\Domain\Candidate\Models\Room;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Reports\Queries\CohortKey;
use App\Domain\Reports\Queries\TabulationSheet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsExaminations;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class, BuildsExaminations::class);

/**
 * Result sheets: the class tabulation sheet, the candidate's certificate and the CSV.
 *
 * The examination here is objective only, so every mark is the server's own — the sheet is about
 * adding marks up, not about how they were given.
 */
beforeEach(function () {
    $this->examWorld();

    $this->exam = $this->approvedExam(
        ['rows' => [['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId('single_best_answer'), 'question_count' => 1, 'marks_each' => 10]]],
        ['total_marks' => 10, 'pass_percentage' => 50],
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

    // Ayesha answers correctly and passes, Bilal answers wrongly and fails.
    sitWith('C-001', $this->correctOptionId);
    sitWith('C-002', $this->wrongOptionId);

    $controllerRole = $this->cmsRole('Results controller');
    $this->cmsGrant($controllerRole, 'exam_results', 'view');
    $this->cmsGrant($controllerRole, 'exam_results_approve', 'view');
    $this->cmsGrant($controllerRole, 'exam_results_publish', 'view');
    $this->controller = $this->staffUser([$controllerRole], $this->branch);

    $this->readerRole = $this->cmsRole('Reports reader');
    $this->cmsGrant($this->readerRole, 'exam_reports', 'view');
    $this->reader = $this->staffUser([$this->readerRole], $this->branch);

    // The examinations this world builds name no intake, so the cohort is "no intake".
    $this->cohortQuery = [
        'programme_id' => $this->exam->programme_id,
        'professional_id' => $this->exam->professional_id,
    ];

    $this->cohort = new CohortKey(
        $this->branch, (int) $this->exam->programme_id, (int) $this->exam->professional_id, null,
    );
});

function sitWith(string $candidateNo, int $optionId): void
{
    $t = test();
    $candidate = Candidate::query()->where('candidate_no', $candidateNo)->firstOrFail();
    $t->actingAs($t->officer, 'web')->post("/exams/{$t->exam->id}/candidates/{$candidate->id}/allocate", ['room_id' => $t->room->id])->assertSessionHasNoErrors();

    $checkedIn = app(CheckInCandidate::class)($t->officer, $t->exam, $candidate->refresh());

    $t->post('/sit/'.$t->exam->sit_code, ['candidate_no' => $candidateNo, 'pin' => $checkedIn['pin']]);
    $attempt = CandidateExam::query()->where('candidate_id', $candidate->id)->firstOrFail();
    $item = $attempt->items()->firstOrFail();
    $t->post("/sit/{$t->exam->sit_code}/answer", ['item_id' => $item->id, 'sequence' => 1, 'payload' => ['selected' => [$optionId]]]);
    $t->post("/sit/{$t->exam->sit_code}/submit");
}

function publishResults(): void
{
    $t = test();
    $t->actingAs($t->controller, 'web')->post("/results/{$t->exam->id}/approve")->assertSessionHasNoErrors();
    $t->actingAs($t->controller, 'web')->post("/results/{$t->exam->id}/publish")->assertSessionHasNoErrors();
}

test('the tabulation sheet totals a published examination, and places the candidates', function () {
    publishResults();

    $sheet = app(TabulationSheet::class)->for($this->cohort);

    expect($sheet['courses'])->toHaveCount(1)
        ->and($sheet['candidates'])->toHaveCount(2)
        ->and($sheet['awaiting'])->toBe([]);

    $top = $sheet['candidates'][0];
    $bottom = $sheet['candidates'][1];

    expect($top['candidateNo'])->toBe('C-001')
        ->and($top['obtainedMarks'])->toBe(10.0)
        ->and($top['possibleMarks'])->toBe(10.0)
        ->and($top['percentage'])->toBe(100.0)
        ->and($top['isPass'])->toBeTrue()
        ->and($top['position'])->toBe(1)
        ->and($bottom['candidateNo'])->toBe('C-002')
        ->and($bottom['percentage'])->toBe(0.0)
        ->and($bottom['isPass'])->toBeFalse()
        ->and($bottom['position'])->toBe(2);
});

test('an examination that is not published yet is awaited, never shown as marks', function () {
    $sheet = app(TabulationSheet::class)->for($this->cohort);

    expect($sheet['courses'])->toBe([])
        ->and($sheet['candidates'])->toBe([])
        ->and($sheet['awaiting'])->toHaveCount(1)
        ->and($sheet['awaiting'][0]['reference'])->toBe($this->exam->public_ref);
});

test('a semester programme gets a GPA from credit hours, and refuses one without them', function () {
    publishResults();

    $cms = config('database.cms_source_database');
    DB::table("{$cms}.acad_programme_profiles")->where('class_id', $this->exam->programme_id)->update(['calendar_type' => 'semester']);
    DB::table("{$cms}.acad_courses")->where('id', $this->exam->course_id)->update(['credit_hours' => 4.0]);

    // The grade point comes from the semester scale, so the results are recompiled under it.
    $this->actingAs($this->controller, 'web')->get("/results/{$this->exam->id}")->assertOk();

    $sheet = app(TabulationSheet::class)->for($this->cohort);

    expect($sheet['calendarType'])->toBe('semester')
        ->and($sheet['creditHoursMissing'])->toBe([])
        // 100% is an A, which is 4.00, over one 4-credit course.
        ->and($sheet['candidates'][0]['gpa'])->toBe(4.0)
        ->and($sheet['candidates'][0]['creditHours'])->toBe(4.0);

    DB::table("{$cms}.acad_courses")->where('id', $this->exam->course_id)->update(['credit_hours' => null]);
    $without = app(TabulationSheet::class)->for($this->cohort);

    expect($without['creditHoursMissing'])->toHaveCount(1)
        ->and($without['candidates'][0]['gpa'])->toBeNull();
});

test('the sheet, the certificate and the CSV are each behind their own permission', function () {
    publishResults();

    $this->actingAs($this->reader, 'web')->get('/reports')->assertOk();
    $this->actingAs($this->reader, 'web')->get('/reports/tabulation?'.http_build_query($this->cohortQuery))->assertOk();
    $this->actingAs($this->reader, 'web')->get('/reports/candidates/C-001?'.http_build_query($this->cohortQuery))->assertOk();

    // Reading is not exporting.
    $this->actingAs($this->reader, 'web')->get('/reports/tabulation.csv?'.http_build_query($this->cohortQuery))->assertForbidden();

    $exporter = $this->staffUser([$this->cmsRole('Exporter')], $this->branch);
    $this->actingAs($exporter, 'web')->get('/reports')->assertForbidden();
});

test('the CSV has a row for every candidate and a column pair for every course', function () {
    publishResults();

    $exportRole = $this->cmsRole('Reports exporter');
    $this->cmsGrant($exportRole, 'exam_reports', 'view');
    $this->cmsGrant($exportRole, 'exam_reports_export', 'view');
    $exporter = $this->staffUser([$exportRole], $this->branch);

    $response = $this->actingAs($exporter, 'web')->get('/reports/tabulation.csv?'.http_build_query($this->cohortQuery));
    $response->assertOk();

    $csv = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect($lines)->toHaveCount(3)
        ->and($lines[0])->toContain('Candidate', 'Name', 'Obtained', 'Position')
        ->and($lines[1])->toContain('C-001')
        ->and($lines[2])->toContain('C-002');
});

test('a candidate certificate shows every course, and 404s for a number nobody used', function () {
    publishResults();

    $this->actingAs($this->reader, 'web')->get('/reports/candidates/C-001?'.http_build_query($this->cohortQuery))
        ->assertInertia(fn ($page) => $page->component('reports/Statement')
            ->where('statement.candidate.candidateNo', 'C-001')
            ->where('statement.candidate.percentage', 100)
            ->has('statement.courses', 1));

    $this->actingAs($this->reader, 'web')->get('/reports/candidates/C-999?'.http_build_query($this->cohortQuery))
        ->assertNotFound();
});

test('a candidate number that two examinations give different CNICs is flagged, not merged', function () {
    publishResults();
    DB::table('cand_candidates')->where('candidate_no', 'C-001')->update(['cnic' => '17301-1111111-1']);

    // A second course of the same year, with the same candidate number on its roster but somebody
    // else's CNIC. Nobody sits it — the point is who the roster says C-001 is.
    $otherCourse = $this->cmsCourse($this->programme, $this->professional, 'RES');
    $second = $this->newExam(['course_id' => $otherCourse, 'title' => 'Respiration paper']);

    DB::table('exm_result_publications')->insert([
        'examination_id' => $second->id, 'status' => 'published',
        'approved_by' => $this->controller->id, 'approved_at' => now(),
        'published_by' => $this->controller->id, 'published_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('cand_candidates')->insert([
        'examination_id' => $second->id, 'branch_id' => $this->branch, 'candidate_no' => 'C-001',
        'name' => 'Ayesha Khan', 'cnic' => '17301-2222222-2', 'status' => 'enrolled',
        'created_by' => $this->officer->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $sheet = app(TabulationSheet::class)->for($this->cohort);

    $flagged = collect($sheet['candidates'])->firstWhere('candidateNo', 'C-001');

    expect($sheet['courses'])->toHaveCount(2)
        ->and($flagged['identityClash'])->toBeTrue()
        // Registered but never sat: an incomplete row, not an absence from the sheet.
        ->and($flagged['satEverything'])->toBeFalse();
});
