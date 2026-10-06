<?php

use App\Domain\Candidate\Models\Candidate;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Exam\Models\Examination;
use App\Domain\Paper\Enums\PaperStatus;
use App\Domain\Paper\Models\Paper;
use App\Domain\Paper\Models\PaperItem;
use App\Domain\QuestionBank\Models\Question;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
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

    $this->post("/sit/{$exam->id}", ['candidate_no' => 'BSCS-1001', 'pin' => '000000'])->assertInvalid(['pin']);
    $this->post("/sit/{$exam->id}", ['candidate_no' => 'BSCS-9999', 'pin' => '482915'])->assertInvalid(['pin']);
    $this->post("/sit/{$exam->id}", ['candidate_no' => 'BSCS-1001', 'pin' => '482915'])->assertRedirect("/sit/{$exam->id}/exam");

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
    $this->post("/sit/{$exam->id}", ['candidate_no' => 'R-1', 'pin' => '123456'])->assertInvalid(['pin' => 'opens at 9:00 AM']);

    $at('09:05');
    $this->post("/sit/{$exam->id}", ['candidate_no' => 'R-1', 'pin' => '123456'])->assertRedirect();
    expect(CandidateExam::query()->latest('id')->first()->deadline_at->equalTo(CarbonImmutable::parse('2026-10-07 10:35', $zone)))->toBeTrue();

    // Starting at 11:00 leaves one hour, not ninety minutes.
    $at('11:00');
    $this->flushSession();
    $this->post("/sit/{$exam->id}", ['candidate_no' => 'R-2', 'pin' => '123456'])->assertRedirect();
    expect(CandidateExam::query()->latest('id')->first()->deadline_at->equalTo(CarbonImmutable::parse('2026-10-07 12:00', $zone)))->toBeTrue();

    $at('12:00');
    $this->flushSession();
    $this->post("/sit/{$exam->id}", ['candidate_no' => 'R-3', 'pin' => '123456'])->assertInvalid(['pin' => 'closed at 12:00 PM']);
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
