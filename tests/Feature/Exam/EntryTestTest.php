<?php

use App\Domain\Candidate\Models\Candidate;
use App\Domain\Candidate\Models\Centre;
use App\Domain\Candidate\Models\Room;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Exam\Models\Examination;
use App\Domain\Paper\Enums\PaperStatus;
use App\Domain\Paper\Models\Paper;
use App\Domain\Paper\Models\PaperItem;
use App\Domain\QuestionBank\Models\Question;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Support\SessionKey;
use Tests\Concerns\BuildsExaminations;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

/*
 * An entry test set up in one step: "this many questions from the course", drawn from questions no
 * other examination has used and shuffled for every candidate; candidates on a roll-number list;
 * one exam PIN for everyone; and a window it is open in.
 */
uses(InteractsWithCms::class, PassesMfa::class, BuildsExaminations::class);

beforeEach(function () {
    $this->examWorld();
    $this->admin = $this->staffUser([$this->cmsRole('Super Admin', true)], $this->branch);
    foreach (range(1, 8) as $i) {
        $this->activeQuestion($i <= 4 ? $this->node : $this->otherNode);
    }
});

/** A new examination of $count questions, one mark each, set up in one step. */
function entryTest(int $count, array $overrides = [])
{
    return test()->actingAs(test()->admin)->post('/exams', test()->examPayload(array_replace([
        'total_marks' => $count, 'question_count' => $count, 'duration_minutes' => 90,
    ], $overrides)));
}

function entryPaperOf(Examination $exam): Paper
{
    return Paper::query()->where('examination_id', $exam->id)->firstOrFail();
}

test('a Super Admin sets up an examination and its published, shuffled paper in one step', function () {
    entryTest(5)->assertSessionHasNoErrors()->assertRedirect();
    $exam = Examination::query()->latest('id')->firstOrFail();
    $paper = entryPaperOf($exam);

    expect($paper->status)->toBe(PaperStatus::Published)
        ->and($paper->shuffle_questions)->toBeTrue()
        ->and($paper->shuffle_options)->toBeTrue()
        ->and(PaperItem::query()->where('paper_id', $paper->id)->count())->toBe(5);

    // The next examination is given only questions the first has not used.
    entryTest(3)->assertSessionHasNoErrors();
    $second = entryPaperOf(Examination::query()->latest('id')->firstOrFail());
    $first = PaperItem::query()->where('paper_id', $paper->id)->pluck('question_id')->all();
    $other = PaperItem::query()->where('paper_id', $second->id)->pluck('question_id')->all();
    expect($other)->toHaveCount(3)->and(array_intersect($first, $other))->toBe([]);
});

test('when the course has too few unused questions, nothing is set up', function () {
    entryTest(5)->assertSessionHasNoErrors();
    $before = Examination::query()->count();

    entryTest(4)->assertSessionHasErrors(['question_count' => 'The course has only 3 questions in use that no other examination has used; 4 are needed. Add questions to the bank, or ask for fewer.']);
    expect(Examination::query()->count())->toBe($before);

    entryTest(3, ['total_marks' => 10])->assertSessionHasErrors('question_count');
});

test('only a Super Admin may set up the paper in one step', function () {
    $this->actingAs($this->setter)->post('/exams', $this->examPayload(['total_marks' => 2, 'question_count' => 2]))->assertForbidden();
    expect(Examination::query()->count())->toBe(0);
});

test('candidates on a roll-number list sign in with the one exam PIN, no check-in needed', function () {
    entryTest(5, ['shared_pin' => '482915'])->assertSessionHasNoErrors();
    $exam = Examination::query()->latest('id')->firstOrFail();
    expect($exam->shared_pin)->toBe('482915');

    $this->actingAs($this->admin)->post("/exams/{$exam->id}/candidates/import", [
        'file' => UploadedFile::fake()->createWithContent('list.csv', "roll_no,name\nBSCS-1001,Ali Raza\nBSCS-1002,Sana Iqbal"),
    ])->assertSessionHasNoErrors();
    expect(Candidate::query()->where('examination_id', $exam->id)->pluck('candidate_no')->sort()->values()->all())->toBe(['BSCS-1001', 'BSCS-1002']);

    $this->post("/sit/{$exam->sit_code}", ['candidate_no' => 'BSCS-1001', 'pin' => '000000'])->assertInvalid(['pin']);
    $this->post("/sit/{$exam->sit_code}", ['candidate_no' => 'BSCS-9999', 'pin' => '482915'])->assertInvalid(['pin']);
    $this->post("/sit/{$exam->sit_code}", ['candidate_no' => 'BSCS-1001', 'pin' => '482915'])->assertRedirect("/sit/{$exam->sit_code}/exam");

    $attempt = CandidateExam::query()->firstOrFail();
    expect($attempt->items()->count())->toBe(5);
});

test('an examination with a window opens at its start, closes on time, and no time runs past the close', function () {
    entryTest(5, ['shared_pin' => '123456', 'starts_at' => '2026-10-07T09:00', 'closes_at' => '2026-10-07T12:00'])->assertSessionHasNoErrors();
    $exam = Examination::query()->latest('id')->firstOrFail();
    $this->actingAs($this->admin)->post("/exams/{$exam->id}/candidates/import", [
        'file' => UploadedFile::fake()->createWithContent('list.csv', "roll_no,name\nR-1,Ali\nR-2,Sana\nR-3,Umar"),
    ]);
    $zone = (string) config('exam.timezone');
    $at = fn (string $time) => $this->travelTo(CarbonImmutable::parse("2026-10-07 {$time}", $zone));

    $at('08:59');
    $this->post("/sit/{$exam->sit_code}", ['candidate_no' => 'R-1', 'pin' => '123456'])->assertInvalid(['pin' => 'opens at 9:00 AM']);

    $at('09:05');
    $this->post("/sit/{$exam->sit_code}", ['candidate_no' => 'R-1', 'pin' => '123456'])->assertRedirect();
    expect(CandidateExam::query()->latest('id')->first()->deadline_at->equalTo(CarbonImmutable::parse('2026-10-07 10:35', $zone)))->toBeTrue();

    // Starting at 11:00 leaves one hour, not ninety minutes.
    $at('11:00');
    $this->flushSession();
    $this->post("/sit/{$exam->sit_code}", ['candidate_no' => 'R-2', 'pin' => '123456'])->assertRedirect();
    expect(CandidateExam::query()->latest('id')->first()->deadline_at->equalTo(CarbonImmutable::parse('2026-10-07 12:00', $zone)))->toBeTrue();

    $at('12:00');
    $this->flushSession();
    $this->post("/sit/{$exam->sit_code}", ['candidate_no' => 'R-3', 'pin' => '123456'])->assertInvalid(['pin' => 'closed at 12:00 PM']);
});

test('the window and the exam PIN are checked when the examination is saved', function () {
    $this->actingAs($this->admin)->post('/exams', $this->examPayload(['starts_at' => '2026-10-07T09:00', 'closes_at' => '2026-10-07T10:00', 'duration_minutes' => 90]))
        ->assertSessionHasErrors('closes_at');
    $this->actingAs($this->admin)->post('/exams', $this->examPayload(['starts_at' => '', 'closes_at' => '2026-10-07T12:00']))
        ->assertSessionHasErrors('starts_at');
    $this->actingAs($this->admin)->post('/exams', $this->examPayload(['shared_pin' => '12ab']))
        ->assertSessionHasErrors('shared_pin');
});

test('when one drawn question gives away another\'s answer, the paper is drawn again around it', function () {
    // Fresh course content: only these questions, two of which clash.
    Question::query()->update(['is_archived' => true]);
    $giver = $this->activeQuestion($this->node, stem: 'A man with crushing chest pain has an acute myocardial infarction; what is the first drug to give?',
        options: [['A', 'Aspirin', true], ['B', 'Insulin', false]]);
    $receiver = $this->activeQuestion($this->node, stem: 'Which diagnosis explains ST elevation with chest pain?',
        options: [['A', 'Acute myocardial infarction', true], ['B', 'Pericarditis', false]]);
    $spare = $this->activeQuestion($this->node, stem: 'Which nerve supplies the deltoid muscle of the shoulder?',
        options: [['A', 'Axillary nerve', true], ['B', 'Radial nerve', false]]);

    entryTest(2)->assertSessionHasNoErrors();
    $ids = PaperItem::query()->where('paper_id', entryPaperOf(Examination::query()->latest('id')->firstOrFail())->id)->pluck('question_id')->sort()->values()->all();

    // Whichever two the draw took, never both of the pair that clash.
    expect($ids)->toHaveCount(2)
        ->and(in_array($giver->question_id, $ids, true) && in_array($receiver->question_id, $ids, true))->toBeFalse();
});

/** An entry test (5 questions unless said) with a roster of $n candidates (R-001 …). */
function entryWithRoster(int $n, array $overrides = [], int $questions = 5): Examination
{
    entryTest($questions, $overrides)->assertSessionHasNoErrors();
    $exam = Examination::query()->latest('id')->firstOrFail();
    test()->actingAs(test()->admin)->post("/exams/{$exam->id}/candidates/import", [
        'file' => UploadedFile::fake()->createWithContent('list.csv', "roll_no,name\n".implode("\n", array_map(fn ($i) => sprintf('R-%03d,Student %d', $i, $i), range(1, $n)))),
    ])->assertSessionHasNoErrors();

    return $exam;
}

test('with one exam PIN, everyone — or the ticked ones — is checked in at once, seated or not, and no PIN is issued', function () {
    $exam = entryWithRoster(4, ['shared_pin' => '246810']);
    $ids = Candidate::query()->where('examination_id', $exam->id)->orderBy('candidate_no')->pluck('id')->all();

    $this->actingAs($this->admin)->get("/exams/{$exam->id}/checkin")->assertOk()
        ->assertInertia(fn ($page) => $page->has('results', 4)->where('counts.enrolled', 4)->where('pages.total', 4));

    $this->actingAs($this->admin)->post("/exams/{$exam->id}/checkin-all", ['candidate_ids' => [$ids[0], $ids[1]]])->assertSessionMissing(SessionKey::FLASH_DATA.'.pins');
    expect(Candidate::query()->whereIn('id', [$ids[0], $ids[1]])->where('status', 'checked_in')->count())->toBe(2)
        ->and(Candidate::query()->whereIn('id', [$ids[2], $ids[3]])->where('status', 'checked_in')->count())->toBe(0)
        ->and(Candidate::query()->whereNotNull('pin_hash')->count())->toBe(0);

    $this->actingAs($this->admin)->post("/exams/{$exam->id}/checkin-all");
    expect(Candidate::query()->where('examination_id', $exam->id)->where('status', 'checked_in')->count())->toBe(4);

    // One by one works too.
    $other = entryWithRoster(1, ['shared_pin' => '135790'], questions: 3);
    $single = Candidate::query()->where('examination_id', $other->id)->firstOrFail();
    $this->actingAs($this->admin)->post("/exams/{$other->id}/checkin/{$single->id}")->assertSessionHasNoErrors()->assertSessionMissing(SessionKey::FLASH_DATA.'.pin');
    expect($single->fresh()->status->value)->toBe('checked_in');
});

test('with PINs of their own, check-in-all checks in the seated, issues each a PIN once, and leaves the unseated out', function () {
    $exam = entryWithRoster(3);
    $this->actingAs($this->admin)->post('/exams/conduct/centres', ['name' => 'Hall', 'code' => 'H-1'])->assertSessionHasNoErrors();
    $centre = Centre::query()->firstOrFail();
    $this->actingAs($this->admin)->post("/exams/conduct/centres/{$centre->id}/rooms", ['name' => 'R1', 'capacity' => 2])->assertSessionHasNoErrors();
    $room = Room::query()->firstOrFail();
    foreach (Candidate::query()->where('examination_id', $exam->id)->orderBy('candidate_no')->limit(2)->get() as $candidate) {
        $this->actingAs($this->admin)->post("/exams/{$exam->id}/candidates/{$candidate->id}/allocate", ['room_id' => $room->id])->assertSessionHasNoErrors();
    }

    $response = $this->actingAs($this->admin)->post("/exams/{$exam->id}/checkin-all");
    $pins = session(SessionKey::FLASH_DATA)['pins'] ?? null;
    expect($pins)->toHaveCount(2)
        ->and($pins[0]['pin'])->toMatch('/^\d{6}$/')
        ->and(Candidate::query()->where('examination_id', $exam->id)->where('status', 'checked_in')->count())->toBe(2)
        ->and(Candidate::query()->where('examination_id', $exam->id)->where('status', 'enrolled')->count())->toBe(1);
    $response->assertRedirect("/exams/{$exam->id}/checkin");

    // The PIN issued works.
    $this->post("/sit/{$exam->sit_code}", ['candidate_no' => $pins[0]['candidateNo'], 'pin' => $pins[0]['pin']])->assertRedirect("/sit/{$exam->sit_code}/exam");
});

test('computers wait for approval only when Setup says so, and a centre\'s waiting computers are approved all at once', function () {
    $exam = entryWithRoster(1, ['shared_pin' => '112233']);
    $this->actingAs($this->admin)->post('/exams/conduct/centres', ['name' => 'Lab', 'code' => 'LAB-1']);
    $centre = Centre::query()->firstOrFail();
    $this->actingAs($this->admin)->post("/exams/conduct/centres/{$centre->id}/rooms", ['name' => 'R1', 'capacity' => 5]);
    $room = Room::query()->firstOrFail();
    $candidate = Candidate::query()->where('examination_id', $exam->id)->firstOrFail();
    $this->actingAs($this->admin)->post("/exams/{$exam->id}/candidates/{$candidate->id}/allocate", ['room_id' => $room->id]);

    $this->post("/sit/{$exam->sit_code}", ['candidate_no' => 'R-001', 'pin' => '112233']);
    $device = fn () => $this->postJson("/sit/{$exam->sit_code}/device", ['fingerprint' => 'Chrome|1920x1080|Asia/Karachi'])->json('status');

    // Off under Setup: no waiting.
    $this->cmsExamSettings(['kmu_assess_device_approval' => 0]);
    app()->forgetScopedInstances();
    expect($device())->toBe('skipped');

    // On: the computer waits until "Approve all".
    $this->cmsExamSettings(['kmu_assess_device_approval' => 1]);
    app()->forgetScopedInstances();
    expect($device())->toBe('pending');
    app('auth')->shouldUse('web');
    $this->actingAs($this->admin)->post("/exams/conduct/centres/{$centre->id}/devices/approve-all")->assertRedirect('/exams/conduct/centres');
    app('auth')->shouldUse('candidate');
    expect($device())->toBe('approved');
});

test('the monitor shows a page at a time, with the whole examination counted', function () {
    $exam = entryWithRoster(3, ['shared_pin' => '999000']);
    $this->post("/sit/{$exam->sit_code}", ['candidate_no' => 'R-001', 'pin' => '999000'])->assertRedirect();

    app('auth')->shouldUse('web');
    $this->actingAs($this->admin)->get("/exams/{$exam->id}/monitor")->assertOk()
        ->assertInertia(fn ($page) => $page->has('attempts', 1)
            ->where('summary.inProgress', 1)
            ->where('pages.total', 1)
            ->where('attempts.0.candidateNo', 'R-001')
            ->where('attempts.0.status', 'in_progress'));
});

test('candidates open an examination by its short random code, never by its number', function () {
    entryTest(5, ['shared_pin' => '102030'])->assertSessionHasNoErrors();
    $exam = Examination::query()->latest('id')->firstOrFail();

    expect($exam->sit_code)->toMatch('/^[a-z0-9]{8}$/');
    $this->get("/sit/{$exam->sit_code}")->assertOk();
    $this->get("/sit/{$exam->id}")->assertNotFound();
    $this->get('/sit/zzzzzzzz')->assertNotFound();
    $this->actingAs($this->admin)->get("/exams/{$exam->id}/candidates")
        ->assertInertia(fn ($page) => $page->where('examination.sitUrl', route('sit.login', $exam)));
});

test('the sign-in page says when the examination opens, counts down to it, and says when it has closed', function () {
    entryTest(5, ['shared_pin' => '102030', 'starts_at' => '2026-10-07T09:00', 'closes_at' => '2026-10-07T12:00'])->assertSessionHasNoErrors();
    $exam = Examination::query()->latest('id')->firstOrFail();
    $zone = (string) config('exam.timezone');

    $this->travelTo(CarbonImmutable::parse('2026-10-07 08:30', $zone));
    $this->get("/sit/{$exam->sit_code}")->assertInertia(fn ($page) => $page
        ->where('opening.state', 'not_yet')->where('opening.secondsToOpen', 1800)
        ->where('opening.opensAt', 'Wednesday 7 October 2026, 9:00 AM'));

    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', $zone));
    $this->get("/sit/{$exam->sit_code}")->assertInertia(fn ($page) => $page->where('opening.state', 'open'));

    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00', $zone));
    $this->get("/sit/{$exam->sit_code}")->assertInertia(fn ($page) => $page->where('opening.state', 'closed'));
});

/** An entry test of three questions with options, so answers can be right or wrong. */
function entryScoredExam(bool $showResult): Examination
{
    Question::query()->update(['is_archived' => true]);
    foreach (['Nerve of the deltoid?' => 'Axillary', 'Unit of force?' => 'Newton', 'Capital of Pakistan?' => 'Islamabad'] as $stem => $right) {
        test()->activeQuestion(test()->node, stem: $stem, options: [['A', $right, true], ['B', 'Something else entirely', false]]);
    }
    entryTest(3, ['shared_pin' => '445566', 'show_result' => $showResult, 'pass_percentage' => 60])->assertSessionHasNoErrors();
    $exam = Examination::query()->latest('id')->firstOrFail();
    test()->actingAs(test()->admin)->post("/exams/{$exam->id}/candidates/import", [
        'file' => UploadedFile::fake()->createWithContent('list.csv', "roll_no,name\nR-1,Ali\nR-2,Sana"),
    ])->assertSessionHasNoErrors();

    return $exam;
}

/** Signs a candidate in, answers every question (the right option, or the wrong one), and submits. */
function entrySitAndSubmit(Examination $exam, string $candidateNo, bool $right): CandidateExam
{
    test()->flushSession();
    app('auth')->forgetGuards();
    test()->post("/sit/{$exam->sit_code}", ['candidate_no' => $candidateNo, 'pin' => '445566'])->assertRedirect("/sit/{$exam->sit_code}/exam");
    $attempt = CandidateExam::query()->whereHas('candidate', fn ($q) => $q->where('candidate_no', $candidateNo))->firstOrFail();
    foreach ($attempt->items()->with('paperItem')->get() as $i => $item) {
        $option = DB::table('qb_question_options')->where('version_id', $item->paperItem->version_id)
            ->where('is_correct', $right)->value('id');
        test()->postJson("/sit/{$exam->sit_code}/answer", ['item_id' => $item->id, 'sequence' => $i + 1, 'payload' => ['selected' => [$option]], 'flagged' => false])->assertOk();
    }
    test()->post("/sit/{$exam->sit_code}/submit")->assertRedirect("/sit/{$exam->sit_code}/submitted");

    return $attempt->refresh();
}

test('on submitting, a candidate sees their score and whether they passed, when the examination says so', function () {
    $exam = entryScoredExam(showResult: true);

    entrySitAndSubmit($exam, 'R-1', right: true);
    $this->get("/sit/{$exam->sit_code}/submitted")->assertInertia(fn ($page) => $page
        ->where('result.awarded', 3)->where('result.total', 3)->where('result.percent', 100)->where('result.passed', true));

    entrySitAndSubmit($exam, 'R-2', right: false);
    $this->get("/sit/{$exam->sit_code}/submitted")->assertInertia(fn ($page) => $page
        ->where('result.awarded', 0)->where('result.passed', false));
});

test('without that setting, the candidate is only told their answers were received', function () {
    $exam = entryScoredExam(showResult: false);
    entrySitAndSubmit($exam, 'R-1', right: true);
    $this->get("/sit/{$exam->sit_code}/submitted")->assertInertia(fn ($page) => $page->where('result', null));
});

test('staff go through a candidate\'s answers question by question, with what they chose and the right answer', function () {
    $exam = entryScoredExam(showResult: false);
    $attempt = entrySitAndSubmit($exam, 'R-2', right: false);

    app('auth')->shouldUse('web');
    $this->actingAs($this->admin)->get("/exams/{$exam->id}/attempts/{$attempt->id}/review")->assertOk()
        ->assertInertia(fn ($page) => $page->component('exams/conduct/AttemptReview')
            ->where('candidate.candidateNo', 'R-2')
            ->where('counts.wrong', 3)->where('counts.correct', 0)
            ->where('score.passed', false)
            ->has('items', 3)
            ->where('items.0.outcome', 'wrong')
            ->where('items.0.options', fn ($options) => collect($options)->contains(fn ($o) => $o['chosen'] && ! $o['isCorrect'])
                && collect($options)->contains(fn ($o) => $o['isCorrect'] && ! $o['chosen'])));

    // Another examination's attempt is not reachable through this one.
    $other = entryOtherExam();
    $this->actingAs($this->admin)->get("/exams/{$other->id}/attempts/{$attempt->id}/review")->assertNotFound();
});

function entryOtherExam(): Examination
{
    test()->activeQuestion(test()->node);
    entryTest(1)->assertSessionHasNoErrors();

    return Examination::query()->latest('id')->firstOrFail();
}
