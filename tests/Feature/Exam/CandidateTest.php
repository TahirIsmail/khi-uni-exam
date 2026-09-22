<?php

use App\Domain\Candidate\Actions\CheckInCandidate;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Candidate\Models\Centre;
use App\Domain\Identity\Authorization\AccessControl;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsExaminations;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class, BuildsExaminations::class);

beforeEach(function () {
    $this->examWorld();
    $this->exam = $this->newExam();

    $this->centreRole = $this->cmsRole('Centre manager');
    $this->cmsGrant($this->centreRole, 'exam_centres', 'view', 'edit');
    $this->centreManager = $this->staffUser([$this->centreRole], $this->branch);

    $this->conductRole = $this->cmsRole('Conduct officer');
    $this->cmsGrant($this->conductRole, 'exam_candidates', 'view', 'edit');
    $this->cmsGrant($this->conductRole, 'exam_allocation', 'view');
    $this->cmsGrant($this->conductRole, 'exam_checkin', 'view');
    $this->cmsGrant($this->conductRole, 'exam_extra_time', 'view');
    $this->conductOfficer = $this->staffUser([$this->conductRole], $this->branch);

    $this->viewerRole = $this->cmsRole('Candidate viewer');
    $this->cmsGrant($this->viewerRole, 'exam_candidates', 'view');
    $this->viewer = $this->staffUser([$this->viewerRole], $this->branch);
});

function makeCentre(int $capacityPerRoom = 2, int $rooms = 1): Centre
{
    test()->actingAs(test()->centreManager)->post('/exams/conduct/centres', [
        'name' => 'Main Hall',
        'code' => 'MH-1',
        'address' => '1 University Road',
    ])->assertSessionHasNoErrors();
    $centre = Centre::query()->latest('id')->firstOrFail();

    for ($i = 1; $i <= $rooms; $i++) {
        test()->actingAs(test()->centreManager)->post("/exams/conduct/centres/{$centre->id}/rooms", [
            'name' => "Room {$i}",
            'capacity' => $capacityPerRoom,
        ])->assertSessionHasNoErrors();
    }

    return $centre->fresh(['rooms']);
}

function importCandidates(array $lines): void
{
    $csv = implode("\n", $lines);
    test()->actingAs(test()->conductOfficer)->post('/exams/'.test()->exam->id.'/candidates/import', [
        'file' => UploadedFile::fake()->createWithContent('candidates.csv', $csv),
    ])->assertSessionHasNoErrors();
}

test('a centre and its rooms can be created and edited', function () {
    $centre = makeCentre(capacityPerRoom: 30, rooms: 2);

    expect($centre->name)->toBe('Main Hall')
        ->and($centre->rooms)->toHaveCount(2);

    $room = $centre->rooms->first();
    $this->actingAs($this->centreManager)->put("/exams/conduct/centres/{$centre->id}", [
        'name' => 'Main Hall (renamed)',
        'code' => 'MH-1',
        'is_active' => true,
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->centreManager)->put("/exams/conduct/centres/{$centre->id}/rooms/{$room->id}", [
        'name' => $room->name,
        'capacity' => 45,
    ])->assertSessionHasNoErrors();

    expect($centre->fresh()->name)->toBe('Main Hall (renamed)')
        ->and($room->fresh()->capacity)->toBe(45);
});

test('a centre code must be unique on the campus', function () {
    makeCentre();

    $this->actingAs($this->centreManager)->post('/exams/conduct/centres', [
        'name' => 'Another Hall',
        'code' => 'MH-1',
    ])->assertInvalid(['code']);
});

test('managing centres needs the right', function () {
    $this->actingAs($this->viewer)->post('/exams/conduct/centres', [
        'name' => 'Main Hall', 'code' => 'MH-1',
    ])->assertForbidden();

    $this->actingAs($this->viewer)->get('/exams/conduct/centres')->assertForbidden();
});

test('candidates are imported from a CSV, duplicates and bad rows are skipped', function () {
    importCandidates([
        'candidate_no,name,roll_no',
        'C-001,Ayesha Khan,R-1',
        'C-002,Bilal Ahmed,R-2',
        ',Missing Number,R-3',
        'C-002,Duplicate In File,R-4',
    ]);

    expect(Candidate::query()->where('examination_id', $this->exam->id)->count())->toBe(2);

    // Importing again, with one new and the already-known number, adds only the new one.
    importCandidates([
        'candidate_no,name',
        'C-001,Ayesha Khan',
        'C-003,Chaudhry Farhan',
    ]);
    expect(Candidate::query()->where('examination_id', $this->exam->id)->count())->toBe(3);
});

test('importing candidates needs the right', function () {
    $this->actingAs($this->viewer)->post("/exams/{$this->exam->id}/candidates/import", [
        'file' => UploadedFile::fake()->createWithContent('candidates.csv', "candidate_no,name\nC-1,A"),
    ])->assertForbidden();
});

test('a file without the required columns is refused', function () {
    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/candidates/import", [
        'file' => UploadedFile::fake()->createWithContent('candidates.csv', "name\nAyesha"),
    ])->assertInvalid(['file']);
});

test('candidates are allocated automatically up to each room capacity', function () {
    $centre = makeCentre(capacityPerRoom: 2, rooms: 1);
    importCandidates([
        'candidate_no,name',
        'C-001,One',
        'C-002,Two',
        'C-003,Three',
    ]);

    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/candidates/allocate", [
        'centre_id' => $centre->id,
    ])->assertSessionHasNoErrors();

    $candidates = Candidate::query()->where('examination_id', $this->exam->id)->orderBy('candidate_no')->get();
    expect($candidates->where('status', 'allocated'))->toHaveCount(2)
        ->and($candidates->where('status', 'enrolled'))->toHaveCount(1);
});

test('a candidate can be allocated by hand, but not once checked in', function () {
    $centre = makeCentre();
    importCandidates(['candidate_no,name', 'C-001,One']);
    $candidate = Candidate::query()->where('candidate_no', 'C-001')->firstOrFail();
    $room = $centre->rooms->first();

    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/candidates/{$candidate->id}/allocate", [
        'room_id' => $room->id,
        'seat_no' => 'A1',
    ])->assertSessionHasNoErrors();
    expect($candidate->fresh()->status->value)->toBe('allocated')
        ->and($candidate->fresh()->seat_no)->toBe('A1');

    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/checkin/{$candidate->id}")->assertSessionHasNoErrors();

    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/candidates/{$candidate->id}/allocate", [
        'room_id' => $room->id,
        'seat_no' => 'A2',
    ])->assertInvalid(['candidate']);
});

test('a candidate must be allocated before they can check in', function () {
    importCandidates(['candidate_no,name', 'C-001,One']);
    $candidate = Candidate::query()->where('candidate_no', 'C-001')->firstOrFail();

    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/checkin/{$candidate->id}")->assertInvalid(['candidate']);
});

test('checking in issues a PIN, stored only as its hash', function () {
    $centre = makeCentre();
    importCandidates(['candidate_no,name', 'C-001,One']);
    $candidate = Candidate::query()->where('candidate_no', 'C-001')->firstOrFail();
    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/candidates/{$candidate->id}/allocate", ['room_id' => $centre->rooms->first()->id]);

    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/checkin/{$candidate->id}")->assertSessionHasNoErrors();

    $candidate->refresh();
    expect($candidate->status->value)->toBe('checked_in')
        ->and($candidate->pin_hash)->not->toBeNull()
        ->and($candidate->checked_in_at)->not->toBeNull();

    // The plain PIN is only ever in the one-time flash, never a column.
    $columns = DB::connection()->getSchemaBuilder()->getColumnListing('cand_candidates');
    expect($columns)->not->toContain('pin');
});

test('reissuing a PIN only works once checked in, and replaces the old one', function () {
    $centre = makeCentre();
    importCandidates(['candidate_no,name', 'C-001,One']);
    $candidate = Candidate::query()->where('candidate_no', 'C-001')->firstOrFail();

    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/checkin/{$candidate->id}/reissue-pin")->assertInvalid(['candidate']);

    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/candidates/{$candidate->id}/allocate", ['room_id' => $centre->rooms->first()->id]);
    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/checkin/{$candidate->id}");
    $firstHash = $candidate->fresh()->pin_hash;

    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/checkin/{$candidate->id}/reissue-pin")->assertSessionHasNoErrors();
    expect($candidate->fresh()->pin_hash)->not->toBe($firstHash);
});

test('extra time is granted with a reason, and can be removed', function () {
    importCandidates(['candidate_no,name', 'C-001,One']);
    $candidate = Candidate::query()->where('candidate_no', 'C-001')->firstOrFail();

    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/candidates/{$candidate->id}/extra-time", [
        'minutes' => 15,
    ])->assertInvalid(['reason']);

    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/candidates/{$candidate->id}/extra-time", [
        'minutes' => 15,
        'reason' => 'Registered learning difficulty',
    ])->assertSessionHasNoErrors();
    expect($candidate->fresh()->extra_time_minutes)->toBe(15);

    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/candidates/{$candidate->id}/extra-time", [
        'minutes' => null,
    ])->assertSessionHasNoErrors();
    expect($candidate->fresh()->extra_time_minutes)->toBeNull();
});

test('granting extra time needs its own right', function () {
    $roleWithoutExtraTime = $this->cmsRole('Allocator only');
    $this->cmsGrant($roleWithoutExtraTime, 'exam_candidates', 'view', 'edit');
    $allocatorOnly = $this->staffUser([$roleWithoutExtraTime], $this->branch);

    importCandidates(['candidate_no,name', 'C-001,One']);
    $candidate = Candidate::query()->where('candidate_no', 'C-001')->firstOrFail();

    $this->actingAs($allocatorOnly)->post("/exams/{$this->exam->id}/candidates/{$candidate->id}/extra-time", [
        'minutes' => 15,
        'reason' => 'x',
    ])->assertForbidden();
});

test('the database refuses to change a checked-in candidate\'s number or examination', function () {
    $centre = makeCentre();
    importCandidates(['candidate_no,name', 'C-001,One']);
    $candidate = Candidate::query()->where('candidate_no', 'C-001')->firstOrFail();
    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/candidates/{$candidate->id}/allocate", ['room_id' => $centre->rooms->first()->id]);
    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/checkin/{$candidate->id}");

    expect(fn () => DB::table('cand_candidates')->where('id', $candidate->id)->update(['candidate_no' => 'C-999']))
        ->toThrow(QueryException::class, 'cannot be changed');
});

test('a checked-in candidate is never deleted', function () {
    $centre = makeCentre();
    importCandidates(['candidate_no,name', 'C-001,One']);
    $candidate = Candidate::query()->where('candidate_no', 'C-001')->firstOrFail();
    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/candidates/{$candidate->id}/allocate", ['room_id' => $centre->rooms->first()->id]);
    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/checkin/{$candidate->id}");

    expect(fn () => DB::table('cand_candidates')->where('id', $candidate->id)->delete())
        ->toThrow(QueryException::class, 'never deleted');
});

test('the candidate roster is not reachable from another campus', function () {
    $otherBranch = $this->cmsBranch('Other Campus');
    $otherRole = $this->cmsRole('Other campus officer');
    $this->cmsGrant($otherRole, 'exam_candidates', 'view');
    $otherOfficer = $this->staffUser([$otherRole], $otherBranch);
    app(AccessControl::class)->forget($otherOfficer);

    $this->actingAs($otherOfficer)->get("/exams/{$this->exam->id}/candidates")->assertNotFound();
});

test('a hashed PIN verifies only the PIN it was made from', function () {
    $centre = makeCentre();
    importCandidates(['candidate_no,name', 'C-001,One']);
    $candidate = Candidate::query()->where('candidate_no', 'C-001')->firstOrFail();
    $this->actingAs($this->conductOfficer)->post("/exams/{$this->exam->id}/candidates/{$candidate->id}/allocate", ['room_id' => $centre->rooms->first()->id]);

    $result = app(CheckInCandidate::class)($this->conductOfficer, $this->exam, $candidate->fresh());

    expect($result['pin'])->toMatch('/^\d{6}$/')
        ->and(Hash::check($result['pin'], $result['candidate']->pin_hash))->toBeTrue()
        ->and(Hash::check('000000', $result['candidate']->pin_hash))->toBeFalse();
});
