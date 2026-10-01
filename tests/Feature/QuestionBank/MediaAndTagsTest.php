<?php

use App\Domain\QuestionBank\Models\Media;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\Tag;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class);

beforeEach(function () {
    Storage::fake('local');
    $this->shareCmsConnection();
    $this->branch = $this->cmsBranch('Main Campus');
    $this->programme = $this->cmsProgramme($this->branch, 'MBBS');
    $this->professional = $this->cmsProfessional($this->programme);
    $this->course = $this->cmsCourse($this->programme, $this->professional, 'CVS');
    $this->node = $this->cmsCurriculumNode($this->course, $this->programme);

    $role = $this->cmsRole('Faculty');
    $this->cmsGrant($role, 'qbank_questions', 'view', 'add');
    $this->author = $this->staffUser([$role], $this->branch);
});

function question(array $overrides = []): array
{
    return array_replace([
        'question_type_id' => (int) QuestionType::query()->where('code', 'single_best_answer')->value('id'),
        'course_id' => test()->course,
        'node_id' => test()->node,
        'stem' => '<p>A 54-year-old man has crushing chest pain radiating to the jaw.</p>',
        'marks' => 1,
        'negative_marks' => 0,
        'exam_type_id' => test()->cmsExamType('annual'),
        'cognitive_level_id' => 2,
        'difficulty_level_id' => 2,
        'options' => [
            ['label' => 'A', 'body' => 'ECG', 'is_correct' => true, 'sort_order' => 1],
            ['label' => 'B', 'body' => 'Chest radiograph', 'is_correct' => false, 'sort_order' => 2],
        ],
    ], $overrides);
}

test('a picture is uploaded with a description and kept out of the public web root', function () {
    $response = $this->actingAs($this->author)->post('/questions/media', [
        'file' => UploadedFile::fake()->image('ecg.png', 600, 400),
        'alt_text' => 'ECG showing ST elevation in leads II, III and aVF',
    ]);

    $response->assertCreated()->assertJsonPath('alt', 'ECG showing ST elevation in leads II, III and aVF');
    $media = Media::query()->firstOrFail();

    expect($response->json('url'))->toBe('/questions/media/'.$media->id)
        ->and($media->branch_id)->toBe($this->branch)
        ->and($media->alt_text)->not->toBe('')
        ->and($media->width)->toBe(600)
        ->and($media->checksum)->toHaveLength(64)
        ->and($media->path)->toStartWith("qbank/{$this->branch}/");

    Storage::disk('local')->assertExists($media->path);
    expect(str_contains($media->path, 'public'))->toBeFalse();
});

test('a picture needs a description, must be an image, and cannot be huge', function () {
    $this->actingAs($this->author)->post('/questions/media', ['file' => UploadedFile::fake()->image('ecg.png')])
        ->assertSessionHasErrors('alt_text');

    $this->actingAs($this->author)->post('/questions/media', [
        'file' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        'alt_text' => 'A PDF',
    ])->assertSessionHasErrors('file');

    $this->actingAs($this->author)->post('/questions/media', [
        'file' => UploadedFile::fake()->image('huge.png')->size(6000),
        'alt_text' => 'Too big',
    ])->assertSessionHasErrors('file');

    expect(Media::query()->count())->toBe(0);
});

test('the same picture uploaded twice in a campus is stored once', function () {
    $file = UploadedFile::fake()->image('ecg.png', 300, 200);
    $first = $this->actingAs($this->author)->post('/questions/media', ['file' => $file, 'alt_text' => 'ECG'])->assertCreated();
    $second = $this->actingAs($this->author)->post('/questions/media', ['file' => $file, 'alt_text' => 'ECG again'])->assertOk();

    expect(Media::query()->count())->toBe(1)
        ->and($second->json('id'))->toBe($first->json('id'));
});

test('pictures are served only to staff of the same campus', function () {
    $media = $this->actingAs($this->author)->post('/questions/media', [
        'file' => UploadedFile::fake()->image('ecg.png'),
        'alt_text' => 'ECG',
    ])->json();

    $this->actingAs($this->author)->get($media['url'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $otherCampusUser = $this->staffUser([], $this->cmsBranch('City Campus'));
    $role = $this->cmsRole('Reader');
    $this->cmsGrant($role, 'qbank_questions', 'view');
    $this->cmsAssignRole((int) $otherCampusUser->cms_staff_id, $role);
    $this->actingAs($otherCampusUser)->get($media['url'])->assertNotFound();

    auth()->logout();
    $this->get($media['url'])->assertRedirect(config('services.kmu_cms.url').'/site/login');
});

test('pictures used in a question are recorded against the version', function () {
    $media = $this->actingAs($this->author)->post('/questions/media', [
        'file' => UploadedFile::fake()->image('ecg.png'),
        'alt_text' => 'ECG',
    ])->json();

    $this->actingAs($this->author)->post('/questions', question([
        'stem' => '<p>What does this ECG show?</p><img src="'.$media['url'].'" alt="ECG">',
    ]))->assertRedirect();

    $version = QuestionVersion::query()->firstOrFail();

    expect($version->stem)->toContain($media['url'])
        ->and($version->media()->count())->toBe(1)
        ->and($version->media()->first()->role)->toBe('stem')
        ->and($version->media()->first()->media_id)->toBe($media['id']);
});

test('a picture from another campus is not recorded even if its address is pasted in', function () {
    $foreign = Media::query()->create([
        'branch_id' => $this->cmsBranch('City Campus'),
        'disk' => 'local', 'path' => 'qbank/other/x.png', 'original_name' => 'x.png',
        'mime_type' => 'image/png', 'size_bytes' => 100, 'checksum' => str_repeat('a', 64),
        'alt_text' => 'Someone else\'s picture', 'uploaded_by' => $this->author->id,
    ]);

    $this->actingAs($this->author)->post('/questions', question([
        'stem' => '<p>Pasted</p><img src="/questions/media/'.$foreign->id.'" alt="x">',
    ]))->assertRedirect();

    expect(QuestionVersion::query()->firstOrFail()->media()->count())->toBe(0);
});

test('authors add tags for their campus, and the same word is one tag', function () {
    $first = $this->actingAs($this->author)->postJson('/questions/tags', ['name' => 'ECG'])->assertCreated()->json();
    $again = $this->actingAs($this->author)->postJson('/questions/tags', ['name' => ' ecg '])->assertOk()->json();

    expect($again['id'])->toBe($first['id'])
        ->and(Tag::query()->count())->toBe(1)
        ->and(Tag::query()->first()->branch_id)->toBe($this->branch);

    $this->actingAs($this->author)->postJson('/questions/tags', ['name' => ''])->assertStatus(422);
    $this->actingAs($this->author)->post('/questions', question(['tag_ids' => [$first['id']]]))->assertRedirect();

    expect(QuestionVersion::query()->firstOrFail()->tags()->count())->toBe(1);
});

test('the discipline can be set on the question, otherwise it comes from the topic', function () {
    $physiology = $this->cmsDiscipline('Physiology');
    $anatomy = $this->cmsDiscipline('Anatomy');
    $nodeWithDiscipline = $this->cmsCurriculumNode($this->course, $this->programme, 'Cardiac cycle', disciplineId: $physiology);

    $this->actingAs($this->author)->post('/questions', question(['node_id' => $nodeWithDiscipline]))->assertRedirect();
    expect(QuestionVersion::query()->latest('id')->first()->discipline_id)->toBe($physiology);

    $this->actingAs($this->author)->post('/questions', question(['node_id' => $nodeWithDiscipline, 'discipline_id' => $anatomy]))->assertRedirect();
    expect(QuestionVersion::query()->latest('id')->first()->discipline_id)->toBe($anatomy);
});

test('the live checks tell the editor which fields are still missing', function () {
    $response = $this->actingAs($this->author)->postJson('/questions/check', ['marks' => 1, 'negative_marks' => 0]);

    $response->assertStatus(422)
        ->assertJsonPath('errors.course_id.0', 'Choose the course.')
        ->assertJsonPath('errors.question_type_id.0', 'Choose the type of question.')
        ->assertJsonPath('errors.stem.0', 'Write the question.');
});

test('uploading and tagging need the permission to write questions', function () {
    $reader = $this->staffUser([$this->cmsRole('Reader')], $this->branch);

    $this->actingAs($reader)->post('/questions/media', [
        'file' => UploadedFile::fake()->image('ecg.png'),
        'alt_text' => 'ECG',
    ])->assertForbidden();
    $this->actingAs($reader)->postJson('/questions/tags', ['name' => 'ECG'])->assertForbidden();

    expect(Media::query()->count())->toBe(0)->and(Tag::query()->count())->toBe(0);
});
