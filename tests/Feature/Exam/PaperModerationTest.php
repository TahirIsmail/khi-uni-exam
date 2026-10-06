<?php

use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Paper\Enums\PaperStatus;
use App\Domain\Paper\Models\Paper;
use App\Domain\Paper\Models\PaperItem;
use App\Domain\Paper\PaperFingerprint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsExaminations;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class, BuildsExaminations::class);

beforeEach(function () {
    $this->examWorld();
    $this->exam = $this->newExam(['total_marks' => 3]);
    $this->url = "/exams/{$this->exam->id}/paper";

    $this->actingAs($this->setter)->put("/exams/{$this->exam->id}/blueprint", $this->blueprintPayload([
        'rows' => [['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId(), 'question_count' => 3, 'marks_each' => 1]],
    ]))->assertSessionHasNoErrors();
    $this->actingAs($this->setter)->post("/exams/{$this->exam->id}/blueprint/submit")->assertSessionHasNoErrors();
    $this->actingAs($this->approver)->post("/exams/{$this->exam->id}/blueprint/approve")->assertSessionHasNoErrors();

    $this->actingAs($this->setter)->post($this->url)->assertSessionHasNoErrors();
    $this->paper = Paper::query()->where('examination_id', $this->exam->id)->firstOrFail();

    foreach (range(1, 3) as $i) {
        $this->activeQuestion($this->node);
    }
});

function fillIt(): void
{
    test()->actingAs(test()->setter)->post(test()->url.'/fill', ['mode' => 'gaps'])->assertSessionHasNoErrors();
}

function paperOf(): Paper
{
    return test()->paper->fresh();
}

function submittedPaper(): void
{
    fillIt();
    test()->actingAs(test()->setter)->post(test()->url.'/submit')->assertSessionHasNoErrors();
}

function approvedPaper(): void
{
    submittedPaper();
    test()->actingAs(test()->approver)->post(test()->url.'/approve')->assertSessionHasNoErrors();
}

function finalisedPaper(): void
{
    approvedPaper();
    test()->actingAs(test()->approver)->post(test()->url.'/finalise')->assertSessionHasNoErrors();
}

test('a paper cannot be submitted until it is complete', function () {
    $this->actingAs($this->setter)->from($this->url)->post($this->url.'/submit')->assertSessionHasErrors('paper');
    expect(paperOf()->status)->toBe(PaperStatus::Draft);

    fillIt();
    $this->actingAs($this->setter)->post($this->url.'/submit')->assertRedirect($this->url);

    expect(paperOf()->status)->toBe(PaperStatus::Submitted)
        ->and(paperOf()->submitted_by)->toBe($this->setter->id)
        ->and(paperOf()->submitted_at)->not->toBeNull()
        ->and(DB::table('sec_audit_logs')->where('action', 'paper.submitted')->where('entity_id', (string) $this->paper->id)->exists())->toBeTrue();

    // Once submitted, its items are frozen — the database refuses a direct change too.
    $item = PaperItem::query()->where('paper_id', $this->paper->id)->first();
    expect(fn () => DB::table('exm_paper_items')->where('id', $item->id)->update(['marks' => 5]))->toThrow(QueryException::class, 'cannot be changed');
    $this->actingAs($this->setter)->from($this->url)->post($this->url.'/fill', ['mode' => 'gaps'])->assertSessionHasErrors('paper');
});

test('nobody approves a paper they started or submitted, even holding the right', function () {
    submittedPaper();

    $this->cmsGrant($this->setterRole, 'exam_papers_approve', 'view');
    app(AccessControl::class)->forget($this->setter);
    $this->actingAs($this->setter)->post($this->url.'/approve')->assertForbidden();
    expect(paperOf()->status)->toBe(PaperStatus::Submitted);

    $this->actingAs($this->approver)->post($this->url.'/approve')->assertRedirect($this->url);
    expect(paperOf()->status)->toBe(PaperStatus::Approved)
        ->and(paperOf()->approved_by)->toBe($this->approver->id)
        ->and(DB::table('sec_audit_logs')->where('action', 'paper.approved')->exists())->toBeTrue();
});

test('the approver sends a paper back with a reason, and it can be submitted again', function () {
    submittedPaper();

    $this->actingAs($this->approver)->from($this->url)->post($this->url.'/send-back', ['reason' => 'short'])->assertSessionHasErrors('reason');
    $this->actingAs($this->approver)->post($this->url.'/send-back', ['reason' => 'Two of the three questions are nearly identical.'])->assertRedirect($this->url);

    expect(paperOf()->status)->toBe(PaperStatus::Draft)
        ->and(paperOf()->return_reason)->toContain('nearly identical')
        ->and(paperOf()->submitted_by)->toBeNull();

    // Items are editable again.
    $item = PaperItem::query()->where('paper_id', $this->paper->id)->first();
    $this->actingAs($this->setter)->post($this->url.'/items/'.$item->id.'/lock', ['locked' => true])->assertSessionHasNoErrors();

    $this->actingAs($this->setter)->post($this->url.'/submit')->assertSessionHasNoErrors();
    expect(paperOf()->return_reason)->toBeNull();
});

test('an approved paper can also be sent back, and cannot be finalised or approved twice', function () {
    approvedPaper();

    $this->actingAs($this->setter)->post($this->url.'/approve')->assertForbidden();
    $this->actingAs($this->approver)->from($this->url)->post($this->url.'/send-back', ['reason' => 'A row needs a different topic after all.'])->assertRedirect($this->url);
    expect(paperOf()->status)->toBe(PaperStatus::Draft)->and(paperOf()->approved_by)->toBeNull();
});

test('a comment can be left, and finalising is refused while any comment is open', function () {
    submittedPaper();
    $item = PaperItem::query()->where('paper_id', $this->paper->id)->first();

    $this->actingAs($this->approver)->post($this->url.'/comments', ['item_id' => $item->id, 'body' => 'The stem of this one is ambiguous.'])->assertSessionHasNoErrors();
    $comment = DB::table('exm_paper_comments')->where('paper_id', $this->paper->id)->first();
    expect($comment->status)->toBe('open')->and($comment->item_id)->toBe($item->id);

    $this->actingAs($this->approver)->post($this->url.'/approve')->assertRedirect($this->url);
    $this->actingAs($this->approver)->from($this->url)->post($this->url.'/finalise')->assertSessionHasErrors('paper');
    expect(paperOf()->status)->toBe(PaperStatus::Approved);

    $this->actingAs($this->approver)->post("{$this->url}/comments/{$comment->id}/resolve", ['resolved' => true])->assertSessionHasNoErrors();
    expect(DB::table('exm_paper_comments')->where('id', $comment->id)->value('status'))->toBe('resolved');

    $this->actingAs($this->approver)->post($this->url.'/finalise')->assertRedirect($this->url);
    expect(paperOf()->status)->toBe(PaperStatus::Finalised);
});

test('a general comment (no item) can be left too, and only the moderator resolves it', function () {
    submittedPaper();
    $this->actingAs($this->setter)->post($this->url.'/comments', ['body' => 'Please check the whole paper reads within 40 minutes.'])->assertSessionHasNoErrors();
    $comment = DB::table('exm_paper_comments')->whereNull('item_id')->first();
    expect($comment->body)->toContain('40 minutes');

    $this->actingAs($this->setter)->from($this->url)->post("{$this->url}/comments/{$comment->id}/resolve", ['resolved' => true])->assertForbidden();
    $this->actingAs($this->approver)->post("{$this->url}/comments/{$comment->id}/resolve", ['resolved' => true])->assertSessionHasNoErrors();
});

test('comments can only be added or resolved while the paper is being moderated', function () {
    fillIt();
    $item = PaperItem::query()->where('paper_id', $this->paper->id)->first();
    $this->actingAs($this->setter)->from($this->url)->post($this->url.'/comments', ['item_id' => $item->id, 'body' => 'Too early.'])->assertSessionHasErrors('paper');

    finalisedPaper();
    $this->actingAs($this->approver)->from($this->url)->post($this->url.'/comments', ['body' => 'Too late.'])->assertSessionHasErrors('paper');

    expect(fn () => DB::table('exm_paper_comments')->insert(['paper_id' => $this->paper->id, 'body' => 'x', 'status' => 'open', 'created_by' => $this->approver->id, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class, 'moderated');
});

test('finalising seals a fingerprint of the paper, and nothing about it can change afterwards', function () {
    approvedPaper();

    $this->actingAs($this->setter)->post($this->url.'/finalise')->assertForbidden();
    $this->actingAs($this->approver)->post($this->url.'/finalise')->assertRedirect($this->url);

    $paper = paperOf();
    expect($paper->status)->toBe(PaperStatus::Finalised)
        ->and($paper->finalised_by)->toBe($this->approver->id)
        ->and($paper->content_hash)->toHaveLength(64)
        ->and($paper->content_hash)->toBe(app(PaperFingerprint::class)->of($paper))
        ->and(DB::table('sec_audit_logs')->where('action', 'paper.finalised')->exists())->toBeTrue();

    // Refused everywhere: the screen, and a direct write.
    $this->actingAs($this->setter)->from($this->url)->post($this->url.'/fill', ['mode' => 'gaps'])->assertSessionHasErrors('paper');
    $this->actingAs($this->approver)->from($this->url)->post($this->url.'/approve')->assertSessionHasErrors('paper');
    expect(fn () => DB::table('exm_papers')->where('id', $paper->id)->update(['shuffle_questions' => false]))->toThrow(QueryException::class, 'no longer a draft');
    expect(fn () => DB::table('exm_papers')->where('id', $paper->id)->delete())->toThrow(QueryException::class, 'never deleted');
});

test('the database allows only the steps of the paper\'s own workflow', function () {
    fillIt();
    $set = fn (string $status) => fn () => DB::table('exm_papers')->where('id', $this->paper->id)->update(['status' => $status]);

    expect($set('approved'))->toThrow(QueryException::class, 'not an allowed status change')
        ->and($set('finalised'))->toThrow(QueryException::class, 'not an allowed status change')
        ->and($set('published'))->toThrow(QueryException::class, 'not an allowed status change');

    DB::table('exm_papers')->where('id', $this->paper->id)->update(['status' => 'submitted']);
    DB::table('exm_papers')->where('id', $this->paper->id)->update(['status' => 'approved']);
    DB::table('exm_papers')->where('id', $this->paper->id)->update(['status' => 'finalised']);
    expect($set('draft'))->toThrow(QueryException::class, 'not an allowed status change');
    DB::table('exm_papers')->where('id', $this->paper->id)->update(['status' => 'published']);
    expect($set('draft'))->toThrow(QueryException::class, 'not an allowed status change');
});

test('publishing needs its own right, separate from finalising', function () {
    finalisedPaper();

    $this->actingAs($this->approver)->post($this->url.'/publish')->assertForbidden();

    $publisherRole = $this->cmsRole('Controller of examinations');
    $this->cmsGrant($publisherRole, 'exam_papers', 'view');
    $this->cmsGrant($publisherRole, 'exam_papers_publish', 'view');
    $publisher = $this->staffUser([$publisherRole], $this->branch);

    $this->actingAs($publisher)->post($this->url.'/publish')->assertRedirect($this->url);
    $paper = paperOf();
    expect($paper->status)->toBe(PaperStatus::Published)->and($paper->published_by)->toBe($publisher->id);

    $this->actingAs($publisher)->from($this->url)->post($this->url.'/publish')->assertSessionHasErrors('paper');
});

test('reopening the blueprint is refused once its paper has been finalised', function () {
    finalisedPaper();

    $this->actingAs($this->approver)->from("/exams/{$this->exam->id}")->post("/exams/{$this->exam->id}/blueprint/reopen", ['reason' => 'Change the topics now.'])
        ->assertSessionHasErrors('blueprint');
    expect(DB::table('exm_blueprints')->where('examination_id', $this->exam->id)->value('status'))->toBe('approved');
});

test('a finalised paper is given a new version to correct it, keeping the old one exactly as it was', function () {
    finalisedPaper();
    $original = paperOf();
    $originalItemIds = PaperItem::query()->where('paper_id', $original->id)->pluck('id');

    $this->actingAs($this->setter)->from($this->url)->post($this->url.'/new-version')->assertForbidden();

    $this->cmsGrant($this->setterRole, 'exam_papers_unlock', 'view');
    app(AccessControl::class)->forget($this->setter);
    $this->actingAs($this->setter)->post($this->url.'/new-version')->assertRedirect($this->url);

    $latest = Paper::query()->where('examination_id', $this->exam->id)->orderByDesc('version_no')->first();
    expect($latest->id)->not->toBe($original->id)
        ->and($latest->version_no)->toBe(2)
        ->and($latest->status)->toBe(PaperStatus::Draft)
        ->and(PaperItem::query()->where('paper_id', $latest->id)->count())->toBe(3)
        ->and(PaperItem::query()->where('paper_id', $latest->id)->where('is_locked', true)->count())->toBe(3);

    // The old version is untouched.
    expect($original->fresh()->status)->toBe(PaperStatus::Finalised)
        ->and(PaperItem::query()->whereIn('id', $originalItemIds)->count())->toBe(3);

    // The examination page now opens the latest version by default; the old one is still reachable.
    $this->actingAs($this->setter)->get($this->url)->assertInertia(fn ($page) => $page
        ->where('paper.versionNo', 2)
        ->where('versions.0.versionNo', 2)->where('versions.0.isCurrent', true)
        ->where('versions.1.versionNo', 1)->where('versions.1.isCurrent', false));
    $this->actingAs($this->setter)->get($this->url.'?version=1')->assertInertia(fn ($page) => $page->where('paper.versionNo', 1)->where('paper.status', 'finalised'));

    // Cannot skip ahead of the true latest version.
    $this->actingAs($this->setter)->from($this->url)->post($this->url.'/new-version')->assertSessionHasErrors('paper');
});

test('access to moderating and locking is gated by course, campus and the specific right', function () {
    submittedPaper();

    $none = $this->staffUser([$this->cmsRole('Cleaner')], $this->branch);
    $this->actingAs($none)->post($this->url.'/approve')->assertForbidden();

    $limited = $this->staffUser([$this->approverRole], $this->branch);
    $this->cmsExamScope($limited, 'programme', $this->cmsProgramme($this->branch, 'BDS'));
    $this->actingAs($limited)->post($this->url.'/approve')->assertForbidden();

    $elsewhere = $this->staffUser([$this->approverRole], $this->cmsBranch('City Campus'));
    app(AccessControl::class)->forget($elsewhere);
    $this->actingAs($elsewhere)->post($this->url.'/approve')->assertNotFound();
    $this->actingAs($elsewhere)->get($this->url)->assertNotFound();
});

test('the screen offers exactly the buttons the CMS role allows, at each stage', function () {
    // "May submit" is the right and the stage; whether it would succeed is the report, read live
    // from what is being built (Blueprint.vue follows the same split).
    $this->actingAs($this->setter)->get($this->url)->assertInertia(fn ($page) => $page
        ->where('can.edit', true)->where('can.submit', true)->where('can.approve', false)
        ->where('report.isComplete', false));

    fillIt();
    $this->actingAs($this->setter)->get($this->url)->assertInertia(fn ($page) => $page->where('can.submit', true));

    $this->actingAs($this->setter)->post($this->url.'/submit')->assertSessionHasNoErrors();
    $this->actingAs($this->setter)->get($this->url)->assertInertia(fn ($page) => $page
        ->where('can.edit', false)->where('can.submit', false)->where('can.approve', false)->where('can.sendBack', false)->where('can.comment', true));
    $this->actingAs($this->approver)->get($this->url)->assertInertia(fn ($page) => $page
        ->where('can.approve', true)->where('can.sendBack', true)->where('can.resolveComments', true));

    $this->actingAs($this->approver)->post($this->url.'/approve')->assertSessionHasNoErrors();
    $this->actingAs($this->approver)->get($this->url)->assertInertia(fn ($page) => $page
        ->where('can.finalise', true)->where('can.sendBack', true)->where('can.approve', false));

    $this->actingAs($this->approver)->post($this->url.'/finalise')->assertSessionHasNoErrors();
    $this->actingAs($this->approver)->get($this->url)->assertInertia(fn ($page) => $page
        ->where('can.finalise', false)->where('can.sendBack', false)->where('can.comment', false)->where('can.unlockVersion', false));
});
