<?php

use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\PrehocAssessment;
use App\Domain\QuestionBank\Models\PrehocDecision;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\Review;
use App\Domain\QuestionBank\Models\ReviewAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class);

beforeEach(function () {
    $this->shareCmsConnection();
    $this->cmsExamSettings();
    $this->branch = $this->cmsBranch('Main Campus');
    $this->programme = $this->cmsProgramme($this->branch, 'MBBS');
    $this->professional = $this->cmsProfessional($this->programme);
    $this->course = $this->cmsCourse($this->programme, $this->professional, 'CVS');
    $this->node = $this->cmsCurriculumNode($this->course, $this->programme, 'Acute coronary syndrome');

    $authorRole = $this->cmsRole('Faculty');
    $this->cmsGrant($authorRole, 'qbank_questions', 'view', 'add');
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
    $this->cmsGrant($approverRole, 'qbank_review_assign', 'view');
    $this->approverRole = $approverRole;
    $this->approver = $this->staffUser([$approverRole], $this->branch);
});

/** A question the author writes and sends for review, which assigns the reviewers. */
function sendForReview(array $overrides = []): QuestionVersion
{
    test()->actingAs(test()->author)->post('/questions', array_replace([
        'question_type_id' => (int) QuestionType::query()->where('code', 'single_best_answer')->value('id'),
        'course_id' => test()->course,
        'node_id' => test()->node,
        'stem' => '<p>A 54-year-old man has crushing chest pain radiating to the jaw for 40 minutes.</p>',
        'lead_in' => 'Which investigation is most useful first?',
        'marks' => 1,
        'negative_marks' => 0,
        'cognitive_level_id' => 2,
        'difficulty_level_id' => 2,
        'options' => [
            ['label' => 'A', 'body' => 'ECG', 'is_correct' => true, 'sort_order' => 1],
            ['label' => 'B', 'body' => 'Chest radiograph', 'is_correct' => false, 'sort_order' => 2],
        ],
        'references' => [['kind' => 'book', 'citation' => 'Harrison, 21st ed', 'locator' => 'p. 1875', 'sort_order' => 1]],
    ], $overrides))->assertRedirect();

    $version = QuestionVersion::query()->latest('id')->firstOrFail();
    test()->actingAs(test()->author)->post("/questions/{$version->question_id}/versions/{$version->id}/submit")->assertRedirect();

    return $version->fresh() ?? $version;
}

/** Everything a reviewer sends when they are happy with a question. */
function reviewPayload(array $overrides = []): array
{
    $codes = DB::table('qb_review_checklist_items')->where('is_active', true)
        ->where(fn ($q) => $q->whereNull('applies_to')->orWhere('applies_to', 'choice'))
        ->orderBy('sort_order')->pluck('code');

    return array_replace([
        'outcome' => 'reviewed',
        'decision_id' => (int) PrehocDecision::query()->where('code', 'accept')->value('id'),
        'comments' => 'A clean, clinically relevant question with a defensible key.',
        'checklist' => $codes->map(fn (string $code): array => ['code' => $code, 'pass' => true])->all(),
        'cognitive_level_id' => 3,
        'difficulty_level_id' => 2,
        'estimated_p' => 0.6,
    ], $overrides);
}

test('sending a question for review assigns a reviewer and keeps the author proposal', function () {
    $version = sendForReview();

    $assignment = ReviewAssignment::query()->firstOrFail();

    expect($version->status)->toBe(VersionStatus::Submitted)
        ->and($assignment->reviewer_id)->toBe($this->reviewer->id)
        ->and($assignment->assigned_by)->toBeNull()
        ->and($assignment->status)->toBe('open')
        ->and($assignment->branch_id)->toBe($this->branch)
        ->and($assignment->due_at?->diffInDays(now()))->toBeLessThan(8);

    $proposal = PrehocAssessment::query()->firstOrFail();
    expect($proposal->source)->toBe('author')
        ->and($proposal->cognitive_level_id)->toBe(2)
        ->and($proposal->assessed_by)->toBe($this->author->id)
        ->and($proposal->is_consolidated)->toBeFalse();

    expect(DB::table('sec_audit_logs')->where('action', 'qbank.review.assigned')->exists())->toBeTrue();
});

test('the author is never asked to review their own question, and neither is anyone outside the campus', function () {
    // The author also holds the reviewer role, and somebody in another campus does too.
    $this->cmsAssignRole((int) $this->author->cms_staff_id, $this->reviewerRole);
    $elsewhere = $this->staffUser([$this->reviewerRole], $this->cmsBranch('City Campus'));

    sendForReview();

    expect(ReviewAssignment::query()->pluck('reviewer_id')->all())->toBe([$this->reviewer->id])
        ->and($elsewhere->id)->not->toBe($this->reviewer->id);
});

test('the work is spread over the reviewers who have the least to do', function () {
    $second = $this->staffUser([$this->reviewerRole], $this->branch);

    sendForReview();
    sendForReview(['stem' => '<p>A child has a barking cough and stridor that is worse at night.</p>']);

    expect(ReviewAssignment::query()->orderBy('id')->pluck('reviewer_id')->all())
        ->toBe([$this->reviewer->id, $second->id]);
});

test('kmu-cms decides how many reviews a question needs', function () {
    $this->cmsExamSettings(['kmu_assess_reviews_required' => 2]);
    $second = $this->staffUser([$this->reviewerRole], $this->branch);

    $version = sendForReview();

    expect(ReviewAssignment::query()->where('version_id', $version->id)->pluck('reviewer_id')->all())
        ->toBe([$this->reviewer->id, $second->id]);
});

test('a reviewer reviews a question, which records the pre-hoc judgement and waits for an approver', function () {
    $version = sendForReview();
    $assignment = ReviewAssignment::query()->firstOrFail();

    $this->actingAs($this->reviewer)
        ->post("/questions/{$version->question_id}/versions/{$version->id}/review", reviewPayload(['assignment_id' => $assignment->id]))
        ->assertRedirect('/reviews');

    $review = Review::query()->firstOrFail();
    $version->refresh();

    expect($version->status)->toBe(VersionStatus::UnderReview)
        ->and($review->outcome)->toBe('reviewed')
        ->and($review->reviewer_id)->toBe($this->reviewer->id)
        ->and($review->checklist)->toHaveCount(8)
        ->and($assignment->fresh()->status)->toBe('submitted');

    $prehoc = PrehocAssessment::query()->where('source', 'reviewer')->firstOrFail();
    expect($prehoc->review_id)->toBe($review->id)
        ->and($prehoc->cognitive_level_id)->toBe(3)
        ->and($prehoc->estimated_p)->toBe(0.6)
        ->and($version->cognitive_level_id)->toBe(2); // still the author's proposal until approval

    expect(DB::table('sec_audit_logs')->whereIn('action', ['qbank.review.submitted', 'qbank.prehoc.recorded'])->count())->toBe(2);
});

test('a submitted review can never be changed or deleted', function () {
    $version = sendForReview();
    $this->actingAs($this->reviewer)->post("/questions/{$version->question_id}/versions/{$version->id}/review", reviewPayload([
        'assignment_id' => ReviewAssignment::query()->value('id'),
    ]));

    $review = Review::query()->firstOrFail();

    expect(fn () => DB::table('qb_reviews')->where('id', $review->id)->update(['comments' => 'Something else']))
        ->toThrow(QueryException::class, 'cannot be changed');
    expect(fn () => DB::table('qb_reviews')->where('id', $review->id)->delete())
        ->toThrow(QueryException::class, 'cannot be deleted');
});

test('asking for changes sends the question back to its author and calls off the other reviews', function () {
    $this->cmsExamSettings(['kmu_assess_reviews_required' => 2]);
    $second = $this->staffUser([$this->reviewerRole], $this->branch);
    $version = sendForReview();

    $mine = ReviewAssignment::query()->where('reviewer_id', $this->reviewer->id)->firstOrFail();
    $theirs = ReviewAssignment::query()->where('reviewer_id', $second->id)->firstOrFail();

    $this->actingAs($this->reviewer)->post("/questions/{$version->question_id}/versions/{$version->id}/review", [
        'assignment_id' => $mine->id,
        'outcome' => 'changes_requested',
        'comments' => 'The lead-in is negative; ask what is true instead.',
    ])->assertRedirect('/reviews');

    $version->refresh();

    expect($version->status)->toBe(VersionStatus::ChangesRequested)
        ->and($version->isEditable())->toBeTrue()
        ->and($theirs->fresh()->status)->toBe('cancelled')
        ->and($theirs->fresh()->cancel_reason)->toContain('back to its author');

    // The author edits it and sends it again, which asks for reviews afresh.
    $this->actingAs($this->author)->put("/questions/{$version->question_id}/versions/{$version->id}", [
        'question_type_id' => $version->question_type_id,
        'course_id' => $this->course,
        'node_id' => $this->node,
        'stem' => '<p>A 54-year-old man has crushing chest pain radiating to the jaw for 40 minutes.</p>',
        'lead_in' => 'Which investigation is most useful first?',
        'marks' => 1,
        'negative_marks' => 0,
        'options' => [
            ['label' => 'A', 'body' => 'ECG', 'is_correct' => true, 'sort_order' => 1],
            ['label' => 'B', 'body' => 'Chest radiograph', 'is_correct' => false, 'sort_order' => 2],
        ],
        'references' => [['kind' => 'book', 'citation' => 'Harrison, 21st ed', 'locator' => 'p. 1875', 'sort_order' => 1]],
    ])->assertRedirect();

    $this->actingAs($this->author)->post("/questions/{$version->question_id}/versions/{$version->id}/submit")->assertRedirect();

    expect($version->fresh()->status)->toBe(VersionStatus::Submitted)
        ->and(ReviewAssignment::query()->where('status', 'open')->count())->toBe(1);
});

test('asking for changes needs a comment, and a review needs a decision', function () {
    $version = sendForReview();
    $assignment = ReviewAssignment::query()->firstOrFail();
    $url = "/questions/{$version->question_id}/versions/{$version->id}/review";

    $this->actingAs($this->reviewer)->from($url)->post($url, ['assignment_id' => $assignment->id, 'outcome' => 'changes_requested'])
        ->assertSessionHasErrors('comments');

    $this->actingAs($this->reviewer)->from($url)->post($url, reviewPayload(['assignment_id' => $assignment->id, 'decision_id' => null]))
        ->assertSessionHasErrors('decision_id');

    // "Revise" is a decision that has to be explained.
    $this->actingAs($this->reviewer)->from($url)->post($url, reviewPayload([
        'assignment_id' => $assignment->id,
        'decision_id' => (int) PrehocDecision::query()->where('code', 'revise')->value('id'),
        'comments' => '',
    ]))->assertSessionHasErrors('comments');

    // The checklist has to be answered in full.
    $this->actingAs($this->reviewer)->from($url)->post($url, reviewPayload(['assignment_id' => $assignment->id, 'checklist' => []]))
        ->assertSessionHasErrors('checklist');

    expect(Review::query()->count())->toBe(0)
        ->and($version->fresh()->status)->toBe(VersionStatus::Submitted);
});

test('only the reviewer who was asked can submit that review, and never for their own question', function () {
    $version = sendForReview();
    $assignment = ReviewAssignment::query()->firstOrFail();
    $url = "/questions/{$version->question_id}/versions/{$version->id}/review";

    $somebodyElse = $this->staffUser([$this->reviewerRole], $this->branch);
    $this->actingAs($somebodyElse)->post($url, reviewPayload(['assignment_id' => $assignment->id]))->assertForbidden();

    $this->actingAs($this->author)->post($url, reviewPayload(['assignment_id' => $assignment->id]))->assertForbidden();

    $outsider = $this->staffUser([$this->cmsRole('Receptionist')], $this->branch);
    $this->actingAs($outsider)->post($url, reviewPayload(['assignment_id' => $assignment->id]))->assertForbidden();

    expect(Review::query()->count())->toBe(0);
});

test('a reviewer who may not record a pre-hoc assessment can still review', function () {
    $plainRole = $this->cmsRole('Reviewer without pre-hoc');
    $this->cmsGrant($plainRole, 'qbank_questions', 'view');
    $this->cmsGrant($plainRole, 'qbank_review', 'view');
    $plain = $this->staffUser([$plainRole], $this->branch);

    $version = sendForReview();
    $assignment = ReviewAssignment::query()->firstOrFail();
    $url = "/questions/{$version->question_id}/versions/{$version->id}/review";

    // The one who was asked is the first reviewer; give this review to the plain reviewer instead.
    $this->actingAs($this->approver)->delete("/questions/{$version->question_id}/versions/{$version->id}/reviewers/{$assignment->id}", ['reason' => 'Away on leave'])->assertRedirect();
    $this->actingAs($this->approver)->post("/questions/{$version->question_id}/versions/{$version->id}/reviewers", ['reviewer_id' => $plain->id])->assertRedirect();
    $theirs = ReviewAssignment::query()->where('reviewer_id', $plain->id)->firstOrFail();

    $this->actingAs($plain)->post($url, reviewPayload(['assignment_id' => $theirs->id, 'cognitive_level_id' => null, 'difficulty_level_id' => null, 'estimated_p' => null]))
        ->assertRedirect('/reviews');

    expect(Review::query()->count())->toBe(1)
        ->and(PrehocAssessment::query()->where('source', 'reviewer')->count())->toBe(0);

    // With values, but without the permission, it is refused.
    $another = sendForReview(['stem' => '<p>A 70-year-old woman has sudden tearing chest pain radiating to the back.</p>']);
    $this->actingAs($this->approver)->post("/questions/{$another->question_id}/versions/{$another->id}/reviewers", ['reviewer_id' => $plain->id]);
    $second = ReviewAssignment::query()->where('version_id', $another->id)->where('reviewer_id', $plain->id)->firstOrFail();

    $this->actingAs($plain)->post("/questions/{$another->question_id}/versions/{$another->id}/review", reviewPayload(['assignment_id' => $second->id]))
        ->assertForbidden();
});

test('an approver approves a reviewed question, which settles its values and puts it into use', function () {
    $version = sendForReview();
    $this->actingAs($this->reviewer)->post("/questions/{$version->question_id}/versions/{$version->id}/review", reviewPayload([
        'assignment_id' => ReviewAssignment::query()->value('id'),
    ]));

    $this->actingAs($this->approver)->post("/questions/{$version->question_id}/versions/{$version->id}/approve", [
        'decision_id' => (int) PrehocDecision::query()->where('code', 'accept')->value('id'),
        'cognitive_level_id' => 3,
        'difficulty_level_id' => 3,
        'estimated_p' => 0.55,
    ])->assertRedirect('/approvals');

    $version->refresh();
    $question = $version->question;

    expect($version->status)->toBe(VersionStatus::Active)
        ->and($version->status->isUsableInExams())->toBeTrue()
        ->and($version->approved_by)->toBe($this->approver->id)
        ->and($version->approved_at)->not->toBeNull()
        ->and($version->activated_at)->not->toBeNull()
        ->and($version->cognitive_level_id)->toBe(3)
        ->and($version->difficulty_level_id)->toBe(3)
        ->and($question->active_version_id)->toBe($version->id);

    $consolidated = PrehocAssessment::query()->where('is_consolidated', true)->firstOrFail();
    expect($consolidated->source)->toBe('consolidated')
        ->and($consolidated->review_id)->toBeNull()
        ->and($consolidated->estimated_p)->toBe(0.55)
        ->and($consolidated->assessed_by)->toBe($this->approver->id)
        ->and(PrehocAssessment::query()->count())->toBe(3); // author, reviewer, consolidated

    expect(DB::table('sec_audit_logs')->whereIn('action', ['qbank.question.approved', 'qbank.question.activated'])->count())->toBe(2);
});

test('when kmu-cms says so, an approved question waits before it can be used', function () {
    $this->cmsExamSettings(['kmu_assess_auto_activate' => 0]);
    $version = sendForReview();
    $this->actingAs($this->reviewer)->post("/questions/{$version->question_id}/versions/{$version->id}/review", reviewPayload([
        'assignment_id' => ReviewAssignment::query()->value('id'),
    ]));

    $this->actingAs($this->approver)->post("/questions/{$version->question_id}/versions/{$version->id}/approve", [
        'decision_id' => (int) PrehocDecision::query()->where('code', 'accept')->value('id'),
        'cognitive_level_id' => 3,
        'difficulty_level_id' => 2,
    ]);

    expect($version->fresh()->status)->toBe(VersionStatus::Approved)
        ->and($version->fresh()->question->active_version_id)->toBeNull();

    $this->actingAs($this->approver)->post("/questions/{$version->question_id}/versions/{$version->id}/activate")->assertRedirect();

    expect($version->fresh()->status)->toBe(VersionStatus::Active)
        ->and($version->fresh()->question->active_version_id)->toBe($version->id);
});

test('the approval gate holds: enough reviews, no failed rule, an accept decision, and not the author', function () {
    $version = sendForReview();
    $url = "/questions/{$version->question_id}/versions/{$version->id}/approve";
    $accept = (int) PrehocDecision::query()->where('code', 'accept')->value('id');

    // Nothing reviewed yet.
    $this->actingAs($this->approver)->from('/approvals')->post($url, ['decision_id' => $accept])->assertSessionHasErrors('status');

    $this->actingAs($this->reviewer)->post("/questions/{$version->question_id}/versions/{$version->id}/review", reviewPayload([
        'assignment_id' => ReviewAssignment::query()->value('id'),
        'checklist' => [
            ['code' => 'cover_the_options', 'pass' => false, 'note' => 'The options give the answer away.'],
            ['code' => 'no_negative_stem', 'pass' => true],
            ['code' => 'homogeneous_options', 'pass' => true],
            ['code' => 'no_absolute_terms', 'pass' => true],
            ['code' => 'no_grammatical_cues', 'pass' => true],
            ['code' => 'key_not_longest', 'pass' => true],
            ['code' => 'answer_defensible', 'pass' => true],
            ['code' => 'clinically_relevant', 'pass' => true],
        ],
    ]));

    // A required rule failed.
    $this->actingAs($this->approver)->from('/approvals')->post($url, ['decision_id' => $accept])
        ->assertSessionHasErrors('checklist');

    expect($version->fresh()->status)->toBe(VersionStatus::UnderReview);

    // A decision that is not an "accept" one cannot approve, even with a clean review.
    $clean = sendForReview(['stem' => '<p>A 70-year-old woman has sudden tearing chest pain radiating to the back.</p>']);
    $this->actingAs($this->reviewer)->post("/questions/{$clean->question_id}/versions/{$clean->id}/review", reviewPayload([
        'assignment_id' => ReviewAssignment::query()->where('version_id', $clean->id)->value('id'),
    ]));

    $this->actingAs($this->approver)->from('/approvals')->post("/questions/{$clean->question_id}/versions/{$clean->id}/approve", [
        'decision_id' => (int) PrehocDecision::query()->where('code', 'hold')->value('id'),
        'comments' => 'Held for the department to discuss.',
    ])->assertSessionHasErrors('decision_id');

    expect($clean->fresh()->status)->toBe(VersionStatus::UnderReview)
        ->and(Review::query()->count())->toBe(2);
});

test('nobody approves their own question, even with the right to approve', function () {
    $this->cmsAssignRole((int) $this->author->cms_staff_id, $this->approverRole);
    $version = sendForReview();
    $this->actingAs($this->reviewer)->post("/questions/{$version->question_id}/versions/{$version->id}/review", reviewPayload([
        'assignment_id' => ReviewAssignment::query()->value('id'),
    ]));

    $this->actingAs($this->author->fresh())->post("/questions/{$version->question_id}/versions/{$version->id}/approve", [
        'decision_id' => (int) PrehocDecision::query()->where('code', 'accept')->value('id'),
    ])->assertForbidden();

    expect($version->fresh()->status)->toBe(VersionStatus::UnderReview);
});

test('when two reviewers decide differently the approver has to say why', function () {
    $this->cmsExamSettings(['kmu_assess_reviews_required' => 2]);
    $second = $this->staffUser([$this->reviewerRole], $this->branch);
    $version = sendForReview();
    $url = "/questions/{$version->question_id}/versions/{$version->id}/review";

    $this->actingAs($this->reviewer)->post($url, reviewPayload([
        'assignment_id' => ReviewAssignment::query()->where('reviewer_id', $this->reviewer->id)->value('id'),
    ]));
    $this->actingAs($second)->post($url, reviewPayload([
        'assignment_id' => ReviewAssignment::query()->where('reviewer_id', $second->id)->value('id'),
        'decision_id' => (int) PrehocDecision::query()->where('code', 'accept_minor')->value('id'),
        'comments' => 'Shorten the vignette a little; otherwise it is fine.',
    ]));

    $approveUrl = "/questions/{$version->question_id}/versions/{$version->id}/approve";
    $accept = (int) PrehocDecision::query()->where('code', 'accept')->value('id');

    $this->actingAs($this->approver)->from('/approvals')->post($approveUrl, ['decision_id' => $accept])
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->approver)->post($approveUrl, [
        'decision_id' => $accept,
        'cognitive_level_id' => 3,
        'difficulty_level_id' => 2,
        'reason' => 'Both reviewers accept it; the wording point is minor and already fixed.',
    ])->assertRedirect('/approvals');

    expect($version->fresh()->status)->toBe(VersionStatus::Active)
        ->and(PrehocAssessment::query()->where('is_consolidated', true)->value('reason'))->toContain('minor');
});

test('turning a question down archives it with the reason', function () {
    $version = sendForReview();
    $this->actingAs($this->reviewer)->post("/questions/{$version->question_id}/versions/{$version->id}/review", reviewPayload([
        'assignment_id' => ReviewAssignment::query()->value('id'),
    ]));

    $url = "/questions/{$version->question_id}/versions/{$version->id}/reject";
    $this->actingAs($this->approver)->from('/approvals')->post($url, ['reason' => 'too short'])->assertSessionHasErrors('reason');

    $this->actingAs($this->approver)->post($url, ['reason' => 'The key is not defensible and the topic is already covered twice.'])
        ->assertRedirect('/approvals');

    $version->refresh();
    $question = Question::query()->findOrFail($version->question_id);

    expect($version->status)->toBe(VersionStatus::Archived)
        ->and($question->is_archived)->toBeTrue()
        ->and($question->archive_reason)->toContain('not defensible')
        ->and(DB::table('sec_audit_logs')->where('action', 'qbank.question.rejected')->exists())->toBeTrue();
});

test('a new version of a question in use takes its place when it is approved', function () {
    $version = sendForReview();
    $this->actingAs($this->reviewer)->post("/questions/{$version->question_id}/versions/{$version->id}/review", reviewPayload([
        'assignment_id' => ReviewAssignment::query()->value('id'),
    ]));
    $this->actingAs($this->approver)->post("/questions/{$version->question_id}/versions/{$version->id}/approve", [
        'decision_id' => (int) PrehocDecision::query()->where('code', 'accept')->value('id'),
        'cognitive_level_id' => 3,
        'difficulty_level_id' => 2,
    ]);

    $this->actingAs($this->author)->post("/questions/{$version->question_id}/versions")->assertRedirect();
    $second = QuestionVersion::query()->where('question_id', $version->question_id)->where('version_no', 2)->firstOrFail();

    $this->actingAs($this->author)->post("/questions/{$second->question_id}/versions/{$second->id}/submit")->assertRedirect();
    $this->actingAs($this->reviewer)->post("/questions/{$second->question_id}/versions/{$second->id}/review", reviewPayload([
        'assignment_id' => ReviewAssignment::query()->where('version_id', $second->id)->value('id'),
    ]));
    $this->actingAs($this->approver)->post("/questions/{$second->question_id}/versions/{$second->id}/approve", [
        'decision_id' => (int) PrehocDecision::query()->where('code', 'accept')->value('id'),
        'cognitive_level_id' => 3,
        'difficulty_level_id' => 2,
    ])->assertRedirect();

    expect($version->fresh()->status)->toBe(VersionStatus::Superseded)
        ->and($second->fresh()->status)->toBe(VersionStatus::Active)
        ->and($second->question->fresh()->active_version_id)->toBe($second->id);
});

test('reviewers can be given the job by hand and taken off it again', function () {
    $version = sendForReview();
    $first = ReviewAssignment::query()->firstOrFail();
    $base = "/questions/{$version->question_id}/versions/{$version->id}";
    $other = $this->staffUser([$this->reviewerRole], $this->branch);

    $this->actingAs($this->approver)->from($base.'/review')->delete("{$base}/reviewers/{$first->id}", ['reason' => 'On leave for a month'])->assertRedirect();
    expect($first->fresh()->status)->toBe('cancelled');

    $this->actingAs($this->approver)->post("{$base}/reviewers", ['reviewer_id' => $other->id])->assertRedirect();
    expect(ReviewAssignment::query()->where('reviewer_id', $other->id)->where('status', 'open')->exists())->toBeTrue();

    // The author cannot be asked, and nobody can be asked twice.
    $this->actingAs($this->approver)->from($base.'/review')->post("{$base}/reviewers", ['reviewer_id' => $this->author->id])
        ->assertSessionHasErrors('reviewer_id');
    $this->actingAs($this->approver)->from($base.'/review')->post("{$base}/reviewers", ['reviewer_id' => $other->id])
        ->assertSessionHasErrors('reviewer_id');

    // Assigning needs its own permission.
    $this->actingAs($this->reviewer)->post("{$base}/reviewers", ['reviewer_id' => $other->id])->assertForbidden();

    expect(DB::table('sec_audit_logs')->where('action', 'qbank.review.cancelled')->exists())->toBeTrue();
});

test('the reviewer queue shows what is mine and is due, and nothing from another campus', function () {
    $version = sendForReview();

    $this->actingAs($this->reviewer)->get('/reviews')->assertOk()->assertInertia(fn ($page) => $page
        ->component('qbank/ReviewQueue')
        ->where('assignments.total', 1)
        ->where('assignments.data.0.reference', $version->question->public_ref)
        ->where('assignments.data.0.status', 'open')
        ->where('canApprove', false));

    $this->actingAs($this->author)->get('/reviews')->assertForbidden();

    // In another campus the same reviewer sees nothing, because the queue is per campus.
    $elsewhere = $this->cmsBranch('City Campus');
    $this->cmsGiveBranch((int) $this->reviewer->cms_staff_id, $elsewhere);
    // One container serves every request of a test, so the campus list read earlier is dropped.
    app(AccessControl::class)->forget($this->reviewer);
    $this->actingAs($this->reviewer)->put('/branch', ['branch_id' => $elsewhere])->assertRedirect();
    $this->actingAs($this->reviewer)->get('/reviews')->assertInertia(fn ($page) => $page->where('assignments.total', 0));
});

test('the approval queue separates what is ready from what is still in review', function () {
    $version = sendForReview();

    $this->actingAs($this->approver)->get('/approvals')->assertOk()->assertInertia(fn ($page) => $page
        ->component('qbank/ApprovalQueue')
        ->where('versions.data', []));

    $this->actingAs($this->approver)->get('/approvals?show=waiting')->assertInertia(fn ($page) => $page
        ->where('versions.data.0.versionId', $version->id)
        ->where('versions.data.0.reviewsIn', 0)
        ->where('versions.data.0.reviewsNeeded', 1)
        ->where('versions.data.0.blockedBecause', fn ($why) => str_contains((string) $why, 'needs 1 review')));

    $this->actingAs($this->reviewer)->post("/questions/{$version->question_id}/versions/{$version->id}/review", reviewPayload([
        'assignment_id' => ReviewAssignment::query()->value('id'),
    ]));

    $this->actingAs($this->approver)->get('/approvals')->assertInertia(fn ($page) => $page
        ->where('versions.data.0.versionId', $version->id)
        ->where('versions.data.0.blockedBecause', null));

    $this->actingAs($this->reviewer)->get('/approvals')->assertForbidden();
});

test('the workspace shows the question, the checklist and what the reviewers said', function () {
    $version = sendForReview();
    $url = "/questions/{$version->question_id}/versions/{$version->id}/review";

    $this->actingAs($this->reviewer)->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->component('qbank/ReviewWorkspace')
        ->where('can.review', true)
        ->where('can.prehoc', true)
        ->where('can.approve', false)
        ->where('myAssignmentId', ReviewAssignment::query()->value('id'))
        ->where('reviewsNeeded', 1)
        ->where('checklistItems', fn ($items) => count($items) === 8)
        ->where('prehoc.0.source', 'author')
        ->where('decisions', fn ($decisions) => count($decisions) === 5));

    $this->actingAs($this->reviewer)->post($url, reviewPayload(['assignment_id' => ReviewAssignment::query()->value('id')]));

    // The author reads the comments on their own question and sees the reviewer's name.
    $this->actingAs($this->author)->get($url)->assertOk()->assertInertia(fn ($page) => $page
        ->where('isAuthor', true)
        ->where('can.review', false)
        ->where('reviews.0.reviewer', $this->reviewer->name)
        ->where('reviews.0.decision', 'Accept')
        ->where('reviews.0.comments', fn ($text) => str_contains((string) $text, 'defensible'))
        ->where('reviews.0.prehoc.estimatedP', 0.6));

    // Somebody with nothing to do with this question cannot open the workspace.
    $this->actingAs($this->staffUser([$this->cmsRole('Reader')], $this->branch))->get($url)->assertForbidden();
});

test('reviewer names are hidden from the author when kmu-cms asks for that', function () {
    $this->cmsExamSettings(['kmu_assess_reviewer_anonymous' => 1]);
    $version = sendForReview();
    $url = "/questions/{$version->question_id}/versions/{$version->id}/review";

    $this->actingAs($this->reviewer)->post($url, reviewPayload(['assignment_id' => ReviewAssignment::query()->value('id')]));

    $this->actingAs($this->author)->get($url)->assertInertia(fn ($page) => $page
        ->where('reviews.0.reviewer', 'A reviewer')
        ->where('reviews.0.comments', fn ($text) => str_contains((string) $text, 'defensible')));

    // The approver still sees who said it.
    $this->actingAs($this->approver)->get($url)->assertInertia(fn ($page) => $page
        ->where('reviews.0.reviewer', $this->reviewer->name));
});

test('the timeline of a question records every step of the review', function () {
    $version = sendForReview();
    $this->actingAs($this->reviewer)->post("/questions/{$version->question_id}/versions/{$version->id}/review", reviewPayload([
        'assignment_id' => ReviewAssignment::query()->value('id'),
    ]));
    $this->actingAs($this->approver)->post("/questions/{$version->question_id}/versions/{$version->id}/approve", [
        'decision_id' => (int) PrehocDecision::query()->where('code', 'accept')->value('id'),
        'cognitive_level_id' => 3,
        'difficulty_level_id' => 2,
    ]);

    $statuses = DB::table('qb_version_status_log')->where('version_id', $version->id)->orderBy('id')->pluck('to_status')->all();

    expect($statuses)->toBe(['submitted', 'under_review', 'approved', 'active']);

    $this->actingAs($this->author)->get("/questions/{$version->question_id}")->assertInertia(fn ($page) => $page
        ->component('qbank/QuestionHistory')
        ->where('versions.0.status', 'active'));
});

test('a question outside the campus or the exam access cannot be reviewed or approved', function () {
    $version = sendForReview();
    $base = "/questions/{$version->question_id}/versions/{$version->id}";

    // A reviewer whose exam access is another course only.
    $otherCourse = $this->cmsCourse($this->programme, $this->professional, 'RESP');
    $limited = $this->staffUser([$this->reviewerRole], $this->branch);
    $this->cmsExamScope($limited, 'course', $otherCourse);

    $this->actingAs($this->approver)->from($base.'/review')->post("{$base}/reviewers", ['reviewer_id' => $limited->id])
        ->assertSessionHasErrors('reviewer_id');

    // An approver of another campus does not even see the question.
    $elsewhere = $this->cmsBranch('City Campus');
    $farApprover = $this->staffUser([$this->approverRole], $elsewhere);
    app(AccessControl::class)->forget($farApprover);
    $this->actingAs($farApprover)->get($base.'/review')->assertNotFound();
    $this->actingAs($farApprover)->post("{$base}/approve", ['decision_id' => 1])->assertNotFound();
});

test('everything in the review is written to the audit log with its campus', function () {
    $version = sendForReview();
    $this->actingAs($this->reviewer)->post("/questions/{$version->question_id}/versions/{$version->id}/review", reviewPayload([
        'assignment_id' => ReviewAssignment::query()->value('id'),
    ]));
    $this->actingAs($this->approver)->post("/questions/{$version->question_id}/versions/{$version->id}/approve", [
        'decision_id' => (int) PrehocDecision::query()->where('code', 'accept')->value('id'),
        'cognitive_level_id' => 3,
        'difficulty_level_id' => 2,
    ]);

    $actions = DB::table('sec_audit_logs')->orderBy('id')->pluck('action')->all();

    expect($actions)->toContain('qbank.review.assigned')
        ->toContain('qbank.review.submitted')
        ->toContain('qbank.prehoc.recorded')
        ->toContain('qbank.question.approved')
        ->toContain('qbank.question.activated')
        ->and(DB::table('sec_audit_logs')->whereNull('branch_id')->count())->toBe(0);

    expect(DB::table('sec_audit_logs')->where('action', 'qbank.review.submitted')->value('actor_id'))->toBe($this->reviewer->id)
        ->and(DB::table('sec_audit_logs')->where('action', 'qbank.question.approved')->value('actor_id'))->toBe($this->approver->id);
});
