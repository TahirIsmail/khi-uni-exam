<?php

/*
 * Acceptance of the first increment (step 13). One test per criterion the blueprint sets for this
 * increment, in its order, so a run of this file answers "is the increment done?".
 *
 * Criteria 1, 2 and 13 are kmu-cms work (academic structures through the UI, Course ID rules, CSRF
 * and the CMS regression checklist); they are proved by the browser tests in that repository —
 * tests/academic/*.mjs and tests/security/*.mjs — and are named in docs/architecture/acceptance.md.
 */

use App\Domain\Audit\AuditVerifier;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\ImportRow;
use App\Domain\QuestionBank\Models\PrehocAssessment;
use App\Domain\QuestionBank\Models\PrehocDecision;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionImport;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\ReviewAssignment;
use App\Support\Cms\CmsSettings;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class);

/** The QBank / academic review that follows the department / subject review(s). */
function academicReviewOf(QuestionVersion $version, array $overrides = []): void
{
    $assignment = ReviewAssignment::query()->where('version_id', $version->id)->where('stage', 'academic')->where('status', 'open')->firstOrFail();
    $codes = DB::table('qb_review_checklist_items')->where('is_active', true)
        ->where(fn ($q) => $q->whereNull('applies_to')->orWhere('applies_to', $version->type->family))
        ->pluck('code');

    test()->actingAs(test()->academic)->post("/questions/{$version->question_id}/versions/{$version->id}/review", array_replace([
        'assignment_id' => $assignment->id,
        'outcome' => 'reviewed',
        'decision_id' => (int) PrehocDecision::query()->where('code', 'accept')->value('id'),
        'comments' => 'Suitable for the question bank; language and key checked.',
        'checklist' => $codes->map(fn (string $code): array => ['code' => $code, 'pass' => true])->all(),
    ], $overrides))->assertRedirect('/reviews');
}

beforeEach(function () {
    $this->shareCmsConnection();
    $this->cmsExamSettings();

    $this->branch = $this->cmsBranch('Main Campus');
    $this->mbbs = $this->cmsProgramme($this->branch, 'MBBS');
    $this->mbbsProfessional = $this->cmsProfessional($this->mbbs);
    $this->mbbsCourse = $this->cmsCourse($this->mbbs, $this->mbbsProfessional, 'CVS');
    $this->mbbsNode = $this->cmsCurriculumNode($this->mbbsCourse, $this->mbbs, 'Acute coronary syndrome');

    $this->dpt = $this->cmsProgramme($this->branch, 'DPT');
    $this->dptProfessional = $this->cmsProfessional($this->dpt);
    $this->dptCourse = $this->cmsCourse($this->dpt, $this->dptProfessional, 'KIN');
    $this->dptNode = $this->cmsCurriculumNode($this->dptCourse, $this->dpt, 'Gait analysis');

    $authorRole = $this->cmsRole('Faculty');
    $this->cmsGrant($authorRole, 'qbank_questions', 'view', 'add');
    $this->cmsGrant($authorRole, 'qbank_import', 'view', 'add');
    $this->cmsGrant($authorRole, 'qbank_questions_export', 'view');
    $this->authorRole = $authorRole;
    $this->author = $this->staffUser([$authorRole], $this->branch);

    $reviewerRole = $this->cmsRole('Reviewer');
    $this->cmsGrant($reviewerRole, 'qbank_questions', 'view');
    $this->cmsGrant($reviewerRole, 'qbank_review', 'view');
    $this->cmsGrant($reviewerRole, 'qbank_prehoc', 'view');
    $this->reviewerRole = $reviewerRole;
    $this->reviewer = $this->staffUser([$reviewerRole], $this->branch);

    $approverRole = $this->cmsRole('Head of department');
    $this->cmsGrant($approverRole, 'qbank_questions', 'view');
    $this->cmsGrant($approverRole, 'qbank_approve', 'view');
    $this->approverRole = $approverRole;
    $this->approver = $this->staffUser([$approverRole], $this->branch);

    $academicRole = $this->cmsRole('QBank academic reviewer');
    $this->cmsGrant($academicRole, 'qbank_questions', 'view');
    $this->cmsGrant($academicRole, 'qbank_review_academic', 'view');
    $this->cmsGrant($academicRole, 'qbank_prehoc', 'view');
    $this->academicRole = $academicRole;
    $this->academic = $this->staffUser([$academicRole], $this->branch);
});

/** A question of the given type, with everything that type requires. */
function ofType(string $code, array $overrides = []): array
{
    $type = QuestionType::query()->where('code', $code)->firstOrFail();

    $question = [
        'question_type_id' => $type->id,
        'course_id' => test()->mbbsCourse,
        'node_id' => test()->mbbsNode,
        'stem' => '<p>A 54-year-old man has crushing chest pain radiating to the jaw for 40 minutes.</p>',
        'lead_in' => 'Which investigation is most useful first?',
        'marks' => 1,
        'negative_marks' => 0,
        'exam_type_id' => test()->cmsExamType('annual'),
        'cognitive_level_id' => 2,
        'difficulty_level_id' => 2,
        'options' => [],
        'items' => [],
        'answers' => [],
        'rubric' => [],
        'references' => [['kind' => 'book', 'citation' => 'Harrison, 21st ed', 'locator' => 'p. 1875', 'sort_order' => 1]],
    ];

    $labels = ['A', 'B', 'C', 'D', 'E'];
    $bodies = ['ECG', 'Chest radiograph', 'Echocardiogram', 'Coronary angiography', 'Troponin'];

    if ($type->has_options) {
        $count = max($type->options_min, 2);
        for ($i = 0; $i < $count; $i++) {
            $question['options'][] = [
                'label' => $labels[$i] ?? 'X'.$i,
                'body' => $code === 'true_false' ? ['True', 'False'][$i] ?? 'Option' : ($bodies[$i] ?? 'Option '.$i),
                'is_correct' => $i < max($type->correct_min, 1),
                'sort_order' => $i + 1,
            ];
        }
    }

    if ($type->has_items) {
        $count = max($type->items_min, 2);
        for ($i = 0; $i < $count; $i++) {
            $item = ['body' => 'Statement '.($i + 1).' about acute coronary syndrome', 'sort_order' => $i + 1];
            $item += match ($type->item_answer->value) {
                'boolean' => ['is_true' => $i % 2 === 0],
                'option' => ['correct_option_label' => $labels[$i] ?? 'A'],
                'text' => ['body' => 'The enzyme measured is [blank]'],
                'position' => [],
                default => [],
            };
            $question['items'][] = $item;
        }
    }

    if ($type->has_accepted_answers) {
        // For a cloze question every blank has its own accepted answers.
        $blanks = $type->item_answer->value === 'text' ? count($question['items']) : 1;
        for ($i = 0; $i < $blanks; $i++) {
            $question['answers'][] = [
                'item_index' => $type->item_answer->value === 'text' ? $i : null,
                'match_mode' => 'exact',
                'answer_text' => 'troponin',
                'tolerance_type' => 'absolute',
                'marks_fraction' => 1,
                'sort_order' => $i + 1,
            ];
        }
    }
    if ($type->has_numeric_answer) {
        $question['answers'] = [['match_mode' => 'numeric', 'numeric_value' => 7.4, 'tolerance' => 0.05, 'tolerance_type' => 'absolute', 'marks_fraction' => 1, 'sort_order' => 1]];
    }
    if ($type->supports_rubric && $type->is_manually_marked) {
        $question['marks'] = 10;
        $question['rubric'][] = ['criterion' => 'Recognises ST elevation and its territory', 'max_marks' => 10, 'sort_order' => 1];
    }

    return array_replace($question, $overrides);
}

function reviewFor(QuestionVersion $version, array $overrides = []): array
{
    $codes = DB::table('qb_review_checklist_items')->where('is_active', true)
        ->where(fn ($q) => $q->whereNull('applies_to')->orWhere('applies_to', $version->type->family))
        ->pluck('code');

    return array_replace([
        'assignment_id' => (int) ReviewAssignment::query()->where('version_id', $version->id)->where('status', 'open')->value('id'),
        'outcome' => 'reviewed',
        'decision_id' => (int) PrehocDecision::query()->where('code', 'accept')->value('id'),
        'comments' => 'A defensible question with a clear key.',
        'checklist' => $codes->map(fn (string $code): array => ['code' => $code, 'pass' => true])->all(),
        'cognitive_level_id' => 3,
        'difficulty_level_id' => 2,
    ], $overrides);
}

test('criterion 3: an author writes one valid question of every type, and an invalid one cannot be sent', function () {
    $codes = QuestionType::query()->where('is_active', true)->orderBy('sort_order')->pluck('code');
    expect($codes)->toHaveCount(12);

    foreach ($codes as $code) {
        $this->actingAs($this->author)->post('/questions', ofType($code))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $version = QuestionVersion::query()->latest('id')->firstOrFail();
        $this->actingAs($this->author)->post("/questions/{$version->question_id}/versions/{$version->id}/submit")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect($version->fresh()->status)->toBe(VersionStatus::Submitted, "{$code} could not be sent for review");
    }

    expect(Question::query()->count())->toBe(12);

    // An unfinished question may be saved as a draft — that is what a draft is for — but it cannot
    // be sent for review, and each error names its own field.
    $incomplete = ofType('single_best_answer', [
        'stem' => '<p>Short</p>',
        'options' => [
            ['label' => 'A', 'body' => 'ECG', 'is_correct' => false, 'sort_order' => 1],
            ['label' => 'B', 'body' => 'Chest radiograph', 'is_correct' => false, 'sort_order' => 2],
        ],
    ]);

    $this->actingAs($this->author)->post('/questions', $incomplete)->assertRedirect();
    $draft = QuestionVersion::query()->latest('id')->firstOrFail();

    $this->actingAs($this->author)->from("/questions/{$draft->question_id}/versions/{$draft->id}/edit")
        ->post("/questions/{$draft->question_id}/versions/{$draft->id}/submit")
        ->assertSessionHasErrors(['stem', 'options']);

    // The editor says the same thing while the author is still typing.
    $this->actingAs($this->author)->postJson('/questions/check', $incomplete)
        ->assertOk()
        ->assertJsonPath('errors.stem.0', fn ($message) => str_contains((string) $message, 'at least'))
        ->assertJsonPath('errors.options.0', 'Mark the correct option.');

    expect($draft->fresh()->status)->toBe(VersionStatus::Draft)
        ->and(Question::query()->count())->toBe(13);
});

test('criterion 4: a submitted version cannot be changed through the UI, the API or raw SQL', function () {
    $this->actingAs($this->author)->post('/questions', ofType('single_best_answer'));
    $version = QuestionVersion::query()->latest('id')->firstOrFail();
    $this->actingAs($this->author)->post("/questions/{$version->question_id}/versions/{$version->id}/submit");

    // The UI does not offer the editor any more.
    $this->actingAs($this->author)->get("/questions/{$version->question_id}/versions/{$version->id}/edit")
        ->assertRedirect("/questions/{$version->question_id}/versions/{$version->id}");

    // The API refuses it.
    $this->actingAs($this->author)->from('/questions')
        ->put("/questions/{$version->question_id}/versions/{$version->id}", ofType('single_best_answer', ['stem' => '<p>Rewritten after submission, which must not be possible.</p>']))
        ->assertSessionHasErrors('status');

    // And so does the database, for anything that changes the content.
    expect(fn () => DB::table('qb_question_versions')->where('id', $version->id)->update(['stem' => '<p>Changed in SQL</p>']))
        ->toThrow(QueryException::class, 'no longer a draft');

    expect($version->fresh()->stem)->toContain('crushing chest pain');
});

test('criterion 5: editing a question in use makes v2, v1 stays in use until v2 is approved', function () {
    $first = approvedQuestion();
    $question = $first->question;

    expect($first->status)->toBe(VersionStatus::Active)
        ->and($question->fresh()->active_version_id)->toBe($first->id);

    $this->actingAs($this->author)->post("/questions/{$question->id}/versions")->assertRedirect();
    $second = QuestionVersion::query()->where('question_id', $question->id)->where('version_no', 2)->firstOrFail();

    $this->actingAs($this->author)->put("/questions/{$question->id}/versions/{$second->id}", ofType('single_best_answer', [
        'stem' => '<p>A 54-year-old woman has crushing chest pain radiating to the jaw for 40 minutes.</p>',
    ]))->assertRedirect();
    $this->actingAs($this->author)->post("/questions/{$question->id}/versions/{$second->id}/submit")->assertRedirect();

    // v1 is still the version an examination would use.
    expect($first->fresh()->status)->toBe(VersionStatus::Active)
        ->and($question->fresh()->active_version_id)->toBe($first->id);

    $this->actingAs($this->reviewer)->post("/questions/{$question->id}/versions/{$second->id}/review", reviewFor($second));
    academicReviewOf($second);
    $this->actingAs($this->approver)->post("/questions/{$question->id}/versions/{$second->id}/approve", [
        'decision_id' => (int) PrehocDecision::query()->where('code', 'accept')->value('id'),
        'cognitive_level_id' => 3,
        'difficulty_level_id' => 2,
    ])->assertRedirect();

    expect($first->fresh()->status)->toBe(VersionStatus::Superseded)
        ->and($second->fresh()->status)->toBe(VersionStatus::Active)
        ->and($question->fresh()->active_version_id)->toBe($second->id);

    // The comparison shows what changed between them.
    $this->actingAs($this->author)->get("/questions/{$question->id}/diff?from={$first->id}&to={$second->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('qbank/QuestionDiff')
            ->where('diff.text.stem.changed', true)
            ->where('diff.text.stem.parts', fn ($parts) => collect($parts)->contains(
                fn ($part) => ($part['type'] ?? '') === 'added' && str_contains((string) ($part['text'] ?? ''), 'woman'),
            )));
});

test('criterion 6: an author cannot review or approve their own question, in the screens or the API', function () {
    // The author holds every reviewing and approving right, and still cannot act on their own work.
    $this->cmsAssignRole((int) $this->author->cms_staff_id, $this->reviewerRole);
    $this->cmsAssignRole((int) $this->author->cms_staff_id, $this->approverRole);
    app(AccessControl::class)->forget($this->author);
    $author = $this->author->fresh();

    $this->actingAs($this->author)->post('/questions', ofType('single_best_answer'));
    $version = QuestionVersion::query()->latest('id')->firstOrFail();
    $this->actingAs($this->author)->post("/questions/{$version->question_id}/versions/{$version->id}/submit");

    // The screens offer them nothing to do.
    $this->actingAs($author)->get("/questions/{$version->question_id}/versions/{$version->id}/review")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('isAuthor', true)
            ->where('can.review', false)
            ->where('can.approve', false));

    $this->actingAs($author)->get('/approvals')
        ->assertInertia(fn ($page) => $page->where('versions.data', []));

    // And the API refuses both.
    $assignment = ReviewAssignment::query()->where('version_id', $version->id)->first();
    if ($assignment !== null) {
        $this->actingAs($author)->post("/questions/{$version->question_id}/versions/{$version->id}/review", reviewFor($version->fresh()))
            ->assertForbidden();
    }
    $this->actingAs($author)->post("/questions/{$version->question_id}/versions/{$version->id}/approve", [
        'decision_id' => (int) PrehocDecision::query()->where('code', 'accept')->value('id'),
    ])->assertForbidden();

    expect($version->fresh()->status)->not->toBe(VersionStatus::Approved);
});

test('criterion 7: every pre-hoc judgement is stored and shown in the timeline', function () {
    $this->cmsExamSettings(['kmu_assess_reviews_required' => 2]);
    $second = $this->staffUser([$this->reviewerRole], $this->branch);

    $this->actingAs($this->author)->post('/questions', ofType('single_best_answer'));
    $version = QuestionVersion::query()->latest('id')->firstOrFail();
    $this->actingAs($this->author)->post("/questions/{$version->question_id}/versions/{$version->id}/submit");

    $this->actingAs($this->reviewer)->post("/questions/{$version->question_id}/versions/{$version->id}/review", reviewFor($version, [
        'assignment_id' => (int) ReviewAssignment::query()->where('version_id', $version->id)->where('reviewer_id', $this->reviewer->id)->value('id'),
        'cognitive_level_id' => 3,
    ]));
    $this->actingAs($second)->post("/questions/{$version->question_id}/versions/{$version->id}/review", reviewFor($version, [
        'assignment_id' => (int) ReviewAssignment::query()->where('version_id', $version->id)->where('reviewer_id', $second->id)->value('id'),
        'cognitive_level_id' => 4,
    ]));
    academicReviewOf($version);
    $this->actingAs($this->approver)->post("/questions/{$version->question_id}/versions/{$version->id}/approve", [
        'decision_id' => (int) PrehocDecision::query()->where('code', 'accept')->value('id'),
        'cognitive_level_id' => 3,
        'difficulty_level_id' => 3,
        'reason' => 'One reviewer said Application, the other Analysis; Application fits the lead-in.',
    ])->assertRedirect();

    // One row per judgement: the author's proposal, one per reviewer, and the consolidated one.
    $rows = PrehocAssessment::query()->where('version_id', $version->id)->orderBy('id')->get();
    expect($rows->pluck('source')->all())->toBe(['author', 'reviewer', 'reviewer', 'consolidated'])
        ->and($rows->where('is_consolidated', true)->count())->toBe(1)
        ->and($rows->pluck('cognitive_level_id')->all())->toBe([2, 3, 4, 3]);

    // And all of them are visible in the timeline, with what each person judged.
    $this->actingAs($this->author)->get("/questions/{$version->question_id}")
        ->assertInertia(fn ($page) => $page
            ->where('timeline', function ($timeline) {
                $what = collect($timeline)->pluck('what')->implode(' || ');

                return str_contains($what, 'Author proposed:')
                    && substr_count($what, 'Reviewer judged:') === 2
                    && str_contains($what, 'Settled on approval:')
                    && str_contains($what, 'Department / Subject review: Accept')
                    && str_contains($what, 'QBank / Academic review: Accept');
            }));
});

test('criterion 8: workflow status, pre-hoc decision and post-hoc decision are separate fields in separate tables', function () {
    $columns = fn (string $table): array => array_map(fn (object $row): string => (string) $row->Field, DB::select("SHOW COLUMNS FROM `{$table}`")); // raw-sql-reviewed: fixed table names below

    expect($columns('qb_question_versions'))->toContain('status')
        ->and($columns('qb_prehoc_assessments'))->toContain('decision_id')->toContain('is_consolidated')
        ->and($columns('qb_posthoc_decisions'))->toContain('decision_type_id')->toContain('observed_p')
        ->and($columns('qb_question_versions'))->not->toContain('prehoc_decision_id')
        ->and($columns('qb_question_versions'))->not->toContain('posthoc_decision_id');

    // Each one has its own history: a version's status log, a pre-hoc row per judgement, and a
    // post-hoc row per examination.
    expect(DB::getSchemaBuilder()->hasTable('qb_version_status_log'))->toBeTrue()
        // After an examination the decision is said in KMU's same five words as before it.
        ->and(DB::table('qb_posthoc_decision_types')->where('is_active', true)->orderBy('sort_order')->pluck('name')->all())
        ->toBe(['Accept', 'Retain in QBank', 'Review', 'Revise', 'Remove / Discard']);
});

test('criterion 9: a large import commits the good rows and reports exactly the bad ones', function () {
    $code = DB::table(config('database.cms_source_database').'.acad_courses')->where('id', $this->mbbsCourse)->value('course_code');
    $this->cmsExamType('annual');
    $header = 'type,course,topic,stem,lead_in,marks,options,correct,exam_type,cognitive,difficulty';
    $lines = [$header];

    // 500 rows: 480 good, 20 that cannot be imported (10 unknown type, 10 with no key).
    for ($i = 1; $i <= 500; $i++) {
        $stem = "A patient presents with symptom number {$i} of this teaching set and needs a decision.";
        if ($i % 50 === 0 && $i <= 500) {
            // Every 50th row: an unknown type (10 rows).
            $lines[] = "\"not-a-type\",\"{$code}\",\"Acute coronary syndrome\",\"{$stem}\",\"Which is first?\",1,\"ECG | Chest radiograph\",A,Annual,Application,Moderate";
        } elseif ($i % 50 === 25) {
            // And another 10 with no answer key.
            $lines[] = "\"sba\",\"{$code}\",\"Acute coronary syndrome\",\"{$stem}\",\"Which is first?\",1,\"ECG | Chest radiograph\",,Annual,Application,Moderate";
        } else {
            $lines[] = "\"sba\",\"{$code}\",\"Acute coronary syndrome\",\"{$stem}\",\"Which is first?\",1,\"ECG | Chest radiograph\",A,Annual,Application,Moderate";
        }
    }

    $file = UploadedFile::fake()->createWithContent('teaching-set.csv', implode("\n", $lines));
    $this->actingAs($this->author)->post('/questions/imports', ['file' => $file])->assertRedirect();

    $import = QuestionImport::query()->firstOrFail();

    expect($import->rows_total)->toBe(500)
        ->and($import->rows_invalid)->toBe(20)
        ->and($import->rows_valid)->toBe(480);

    // The report names the line and the reason for each of the twenty.
    $bad = ImportRow::query()->where('import_id', $import->id)->whereNot('status', 'valid')->get();
    expect($bad)->toHaveCount(20)
        ->and($bad->filter(fn ($row): bool => str_contains(collect($row->errors)->flatten()->implode(' '), 'no question type called'))->count())->toBe(10)
        ->and($bad->filter(fn ($row): bool => str_contains(collect($row->errors)->flatten()->implode(' '), 'Mark the correct option'))->count())->toBe(10);

    $this->actingAs($this->author)->post("/questions/imports/{$import->id}/commit")->assertRedirect();

    expect($import->fresh()->rows_committed)->toBe(480)
        ->and(Question::query()->count())->toBe(480);

    // The same file again: every row is now a duplicate of something in the bank.
    $this->actingAs($this->author)->post('/questions/imports', [
        'file' => UploadedFile::fake()->createWithContent('teaching-set.csv', implode("\n", $lines)),
    ]);
    $again = QuestionImport::query()->latest('id')->firstOrFail();

    expect(ImportRow::query()->where('import_id', $again->id)->where('status', 'valid')
        ->get()->filter(fn ($row): bool => str_contains(implode(' ', $row->warnings ?? []), 'already in the bank'))->count())
        ->toBe(480);
})->group('slow');

test('criterion 11: a user limited to one programme cannot see, search, open or export another', function () {
    // An MBBS question and a DPT question, both in this campus.
    $this->actingAs($this->author)->post('/questions', ofType('single_best_answer'));
    $mbbsVersion = QuestionVersion::query()->latest('id')->firstOrFail();

    $this->actingAs($this->author)->post('/questions', ofType('single_best_answer', [
        'course_id' => $this->dptCourse,
        'node_id' => $this->dptNode,
        'stem' => '<p>A patient walks with an antalgic gait after a knee injury three weeks ago.</p>',
    ]));
    $dptVersion = QuestionVersion::query()->latest('id')->firstOrFail();

    // A DPT lecturer, limited to the DPT programme.
    $dptUser = $this->staffUser([$this->authorRole], $this->branch);
    $this->cmsExamScope($dptUser, 'programme', $this->dpt);
    app(AccessControl::class)->forget($dptUser);

    // The search shows only their own programme's questions.
    $this->actingAs($dptUser)->get('/questions?status=all')->assertOk()->assertInertia(fn ($page) => $page
        ->where('questions.total', 1)
        ->where('questions.data.0.id', $dptVersion->question_id));

    // Searching for the words of the MBBS question finds nothing.
    $this->actingAs($dptUser)->get('/questions?search=crushing+chest+pain')
        ->assertInertia(fn ($page) => $page->where('questions.data', []));

    // The MBBS question cannot be opened by its address, in any of the ways it is addressed.
    $this->actingAs($dptUser)->get("/questions/{$mbbsVersion->question_id}")->assertForbidden();
    $this->actingAs($dptUser)->get("/questions/{$mbbsVersion->question_id}/versions/{$mbbsVersion->id}")->assertForbidden();
    $this->actingAs($dptUser)->get("/questions/{$mbbsVersion->question_id}/diff?from={$mbbsVersion->id}&to={$mbbsVersion->id}")->assertForbidden();
    $this->actingAs($dptUser)->get("/questions/curriculum?course_id={$this->mbbsCourse}")->assertForbidden();

    // And the export holds only what they may see.
    $csv = $this->actingAs($dptUser)->get('/questions/export')->assertOk()->streamedContent();

    expect($csv)->toContain('antalgic gait')
        ->and($csv)->not->toContain('crushing chest pain');

    // Exporting is its own permission.
    $noExport = $this->cmsRole('Faculty without export');
    $this->cmsGrant($noExport, 'qbank_questions', 'view', 'add');
    $this->actingAs($this->staffUser([$noExport], $this->branch))->get('/questions/export')->assertForbidden();
});

test('criterion 12: every step is audited with actor, IP and values, the chain verifies, and tampering is found', function () {
    $version = approvedQuestion();

    $entries = DB::table('sec_audit_logs')->orderBy('id')->get();
    $actions = $entries->pluck('action')->all();

    expect($actions)->toContain('qbank.question.created')
        ->toContain('qbank.version.submitted')
        ->toContain('qbank.review.assigned')
        ->toContain('qbank.review.submitted')
        ->toContain('qbank.prehoc.recorded')
        ->toContain('qbank.question.approved')
        ->toContain('qbank.question.activated');

    $approval = $entries->firstWhere('action', 'qbank.question.approved');
    expect($approval->actor_id)->toBe($this->approver->id)
        ->and($approval->ip)->not->toBeNull()
        ->and($approval->branch_id)->toBe($this->branch)
        ->and(json_decode((string) $approval->old_values, true))->toBe(['status' => 'under_review'])
        ->and(json_decode((string) $approval->new_values, true)['status'])->toBe('approved');

    // The chain of the whole log verifies.
    $verifier = app(AuditVerifier::class);
    expect($verifier->verify()['ok'])->toBeTrue();

    // An entry cannot be altered or removed at all: the database refuses both.
    $target = $entries->firstWhere('action', 'qbank.review.submitted');
    expect(fn () => DB::table('sec_audit_logs')->where('id', $target->id)->update(['action' => 'qbank.review.tampered']))
        ->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::table('sec_audit_logs')->where('id', $target->id)->delete())
        ->toThrow(QueryException::class, 'append-only');

    // So the only way to put something in by hand is to write a row straight into the table, and
    // the chain gives that away with the id of the row that broke it.
    $head = DB::table('sec_audit_chain_head')->first();
    $forgedId = DB::table('sec_audit_logs')->insertGetId([
        'occurred_at' => now()->format('Y-m-d H:i:s.v'),
        'actor_type' => 'staff',
        'actor_id' => $this->approver->id,
        'action' => 'qbank.question.approved',
        'entity_type' => 'question_version',
        'entity_id' => $version->id,
        'branch_id' => $this->branch,
        'prev_hash' => $head->last_hash,
        'row_hash' => str_repeat('a', 64),
    ]);

    $result = $verifier->verify();
    expect($result['ok'])->toBeFalse()
        ->and($result['first_broken_id'])->toBe($forgedId)
        ->and($version->fresh()->status)->toBe(VersionStatus::Active);
});

test('criterion 14: when two-factor is on in kmu-cms, staff who approve or administer must use it', function () {
    $this->cmsMfa(true);

    // Everybody who works in the module, not only the approvers: the university asked for all staff.
    // `be` signs the user in without the "already passed two-factor" session this suite primes.
    foreach ([$this->author, $this->reviewer, $this->approver] as $user) {
        $this->flushSession();
        $this->be($user)->get('/dashboard')->assertRedirect('/mfa/setup');
    }

    $superAdmin = $this->staffUser([$this->cmsRole('Super Admin', superAdmin: true)], $this->branch);
    $this->flushSession();
    $this->be($superAdmin)->get('/dashboard')->assertRedirect('/mfa/setup');

    // With it off again, they go straight in. (One container serves every request of a test, so
    // the settings read earlier are dropped first.)
    $this->cmsMfa(false);
    $this->app->forgetInstance(CmsSettings::class);
    $this->flushSession();
    $this->be($this->approver)->get('/dashboard')->assertOk();
});

/** A question that has been written, reviewed and approved, so a criterion can start from there. */
function approvedQuestion(): QuestionVersion
{
    test()->actingAs(test()->author)->post('/questions', ofType('single_best_answer'))->assertRedirect();
    $version = QuestionVersion::query()->latest('id')->firstOrFail();

    test()->actingAs(test()->author)->post("/questions/{$version->question_id}/versions/{$version->id}/submit")->assertRedirect();
    test()->actingAs(test()->reviewer)->post("/questions/{$version->question_id}/versions/{$version->id}/review", reviewFor($version))->assertRedirect();
    academicReviewOf($version);
    test()->actingAs(test()->approver)->post("/questions/{$version->question_id}/versions/{$version->id}/approve", [
        'decision_id' => (int) PrehocDecision::query()->where('code', 'accept')->value('id'),
        'cognitive_level_id' => 3,
        'difficulty_level_id' => 2,
    ])->assertRedirect();

    return $version->fresh() ?? $version;
}
