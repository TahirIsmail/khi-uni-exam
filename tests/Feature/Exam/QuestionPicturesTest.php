<?php

use App\Domain\Candidate\Actions\CheckInCandidate;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Candidate\Models\Centre;
use App\Domain\Candidate\Models\Room;
use App\Domain\QuestionBank\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsExaminations;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

/*
 * A picture in a question (a diagram in a Biology question) is stored once and served to staff at
 * /questions/media/{id}. A candidate is not staff, so the exam screen points them at their own
 * exam's address for it, which serves only the pictures of questions on their own paper.
 */
uses(InteractsWithCms::class, PassesMfa::class, BuildsExaminations::class);

beforeEach(function () {
    $this->examWorld();

    $this->exam = $this->approvedExam(
        ['rows' => [['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId(), 'question_count' => 3, 'marks_each' => 1]]],
        ['total_marks' => 3],
    );
    // One of the three questions shows a picture; another picture belongs to no question on the paper.
    Storage::fake('local');
    $picture = fn (string $name): Media => Media::query()->create([
        'branch_id' => $this->branch, 'disk' => 'local', 'path' => "qbank/{$name}.png", 'original_name' => "{$name}.png",
        'mime_type' => 'image/png', 'size_bytes' => 4, 'checksum' => hash('sha256', $name), 'alt_text' => $name,
        'uploaded_by' => $this->setter->id,
    ]);
    $this->diagram = $picture('diagram');
    $this->elsewhere = $picture('elsewhere');
    Storage::disk('local')->put('qbank/diagram.png', 'PNG!');
    Storage::disk('local')->put('qbank/elsewhere.png', 'PNG!');
    $this->activeQuestion($this->node, stem: 'Which neuron is the associative one? <img src="/questions/media/'.$this->diagram->id.'" alt="diagram">', mediaIds: [$this->diagram->id]);
    foreach (range(1, 2) as $i) {
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

test('a candidate sees the pictures of their own questions, and no other picture', function () {
    $this->post('/sit/'.$this->exam->sit_code, ['candidate_no' => 'C-001', 'pin' => $this->pin])->assertRedirect();

    $own = "/sit/{$this->exam->sit_code}/media/{$this->diagram->id}";
    $this->get("/sit/{$this->exam->sit_code}/exam")->assertInertia(fn ($page) => $page
        ->where('items', fn ($items) => collect($items)->contains(fn ($item) => str_contains($item['stem'], 'src="'.$own.'"')
            && ! str_contains($item['stem'], '/questions/media/'))));

    $this->get($own)->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get("/sit/{$this->exam->sit_code}/media/{$this->elsewhere->id}")->assertNotFound();
    // The staff address stays closed to a candidate, whose browser holds no staff sign-in. (The
    // test's one app instance still has the officer who set the exam up, and the candidate's guard.)
    app('auth')->shouldUse('web');
    app('auth')->guard('web')->forgetUser();
    $this->get("/questions/media/{$this->diagram->id}")->assertRedirect('/login');
});

test('a picture cannot be fetched without signing in to the exam', function () {
    $this->get("/sit/{$this->exam->sit_code}/media/{$this->diagram->id}")->assertRedirect("/sit/{$this->exam->sit_code}");
});

test('the staff preview keeps the staff address for pictures', function () {
    $this->actingAs($this->setter)->get("/exams/{$this->exam->id}/preview")->assertOk()
        ->assertInertia(fn ($page) => $page->where('items', fn ($items) => collect($items)->contains(fn ($item) => str_contains($item['stem'], '/questions/media/'.$this->diagram->id))));
});
