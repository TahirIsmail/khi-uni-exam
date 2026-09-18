<?php

namespace App\Http\Controllers\QuestionBank;

use App\Domain\Identity\ActiveBranch;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\ReviewAssignment;
use App\Domain\QuestionBank\Queries\QuestionEditorData;
use App\Domain\QuestionBank\Review\AssignReviewers;
use App\Domain\QuestionBank\Review\ReviewBoard;
use App\Domain\QuestionBank\Review\ReviewerPool;
use App\Domain\QuestionBank\Review\ReviewInput;
use App\Domain\QuestionBank\Review\SubmitReview;
use App\Domain\QuestionBank\Validation\VersionContentReader;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reviewing questions: a reviewer's own queue, the workspace where one question is read as a
 * candidate would see it and reviewed, and the assignment of reviewers.
 *
 * The workspace serves reviewers, approvers and the author, showing each of them what they may do;
 * every action checks its own permission, campus and exam access again.
 */
class ReviewController extends Controller
{
    public function __construct(
        private readonly ActiveBranch $activeBranch,
        private readonly ReviewBoard $board,
        private readonly QuestionEditorData $editorData,
    ) {}

    /** The questions waiting for me. */
    public function index(Request $request): Response
    {
        $input = $request->validate(['show' => ['nullable', 'in:open,done,all']]);
        $show = $input['show'] ?? 'open';

        return Inertia::render('qbank/ReviewQueue', [
            'assignments' => $this->board->myQueue($request->user(), $this->branchId($request), $show),
            'show' => $show,
            'canApprove' => $request->user()->can('qbank.question.approve'),
        ]);
    }

    /** One question: read it, see what other reviewers said, and say what you think. */
    public function show(Request $request, Question $question, QuestionVersion $version, VersionContentReader $reader, ReviewerPool $pool): Response
    {
        $this->authoriseVersion($request, $question, $version);
        $user = $request->user();

        $mayReview = $pool->allows($user, $version);
        $mayApprove = $user->can('qbank.question.approve') && $version->author_id !== $user->id;
        $mayAssign = $user->can('qbank.review.assign');
        $isAuthor = $version->author_id === $user->id;
        abort_unless($mayReview || $mayApprove || $mayAssign || $isAuthor, 403, 'You have nothing to do with the review of this question.');

        $reviewers = $mayAssign
            ? array_map(fn (array $row): array => [
                'id' => $row['user']->id,
                'name' => $row['user']->name,
                'openLoad' => $row['openLoad'],
            ], $pool->forVersion($version))
            : [];

        return Inertia::render('qbank/ReviewWorkspace', [
            'reference' => $question->public_ref,
            'questionId' => $question->id,
            'version' => $this->editorData->version($version),
            'isAuthor' => $isAuthor,
            'can' => [
                'review' => $mayReview && $this->board->forVersion($user, $version, true)['myAssignmentId'] !== null,
                'prehoc' => $user->can('qbank.prehoc.record'),
                'approve' => $mayApprove,
                'assign' => $mayAssign,
            ],
            'reviewers' => $reviewers,
            ...$this->board->forVersion($user, $version, $this->board->namesVisibleTo($user, $version)),
            ...$this->editorData->lookups(),
        ]);
    }

    /** A reviewer's outcome: request changes, or a review with a decision and the checklist. */
    public function store(Request $request, Question $question, QuestionVersion $version, SubmitReview $submit): RedirectResponse
    {
        $this->authoriseVersion($request, $question, $version);

        $input = $request->validate([
            'assignment_id' => ['required', 'integer', 'min:1'],
            'outcome' => ['required', 'in:reviewed,changes_requested'],
            'decision_id' => ['nullable', 'integer', 'min:1', 'max:255'],
            'comments' => ['nullable', 'string', 'max:5000'],
            'checklist' => ['array', 'max:50'],
            'checklist.*.code' => ['required', 'string', 'max:40'],
            'checklist.*.pass' => ['required', 'boolean'],
            'checklist.*.note' => ['nullable', 'string', 'max:500'],
            'cognitive_level_id' => ['nullable', 'integer', 'min:1', 'max:255'],
            'difficulty_level_id' => ['nullable', 'integer', 'min:1', 'max:255'],
            'estimated_p' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        $assignment = ReviewAssignment::query()
            ->where('version_id', $version->id)
            ->whereKey((int) $input['assignment_id'])
            ->firstOrFail();

        $review = $submit($request->user(), $assignment, new ReviewInput(
            outcome: (string) $input['outcome'],
            decisionId: isset($input['decision_id']) ? (int) $input['decision_id'] : null,
            comments: $input['comments'] ?? null,
            checklist: $input['checklist'] ?? [],
            cognitiveLevelId: isset($input['cognitive_level_id']) ? (int) $input['cognitive_level_id'] : null,
            difficultyLevelId: isset($input['difficulty_level_id']) ? (int) $input['difficulty_level_id'] : null,
            estimatedP: isset($input['estimated_p']) ? (float) $input['estimated_p'] : null,
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => $review->requestedChanges()
            ? __('Sent back to the author with your comments.')
            : __('Your review was recorded.')]);

        return to_route('reviews.index');
    }

    /** Give this question to a particular reviewer. */
    public function assign(Request $request, Question $question, QuestionVersion $version, AssignReviewers $assign): RedirectResponse
    {
        $this->authoriseVersion($request, $question, $version);

        $input = $request->validate(['reviewer_id' => ['required', 'integer', 'min:1']]);
        $reviewer = User::query()->where('is_active', true)->findOrFail((int) $input['reviewer_id']);

        $assign->to($request->user(), $version, $reviewer);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was asked to review it.', ['name' => $reviewer->name])]);

        return back();
    }

    /** Take the review back, so somebody else can be asked. */
    public function cancelAssignment(Request $request, Question $question, QuestionVersion $version, ReviewAssignment $assignment, AssignReviewers $assign): RedirectResponse
    {
        $this->authoriseVersion($request, $question, $version);
        abort_unless($assignment->version_id === $version->id, 404);

        $input = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);
        $assign->cancel($request->user(), $assignment, (string) $input['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The review was taken back.')]);

        return back();
    }

    private function authoriseVersion(Request $request, Question $question, QuestionVersion $version): void
    {
        abort_unless((int) $version->question_id === $question->id, 404);
        abort_unless((int) $question->branch_id === $this->branchId($request), 404);
    }

    private function branchId(Request $request): int
    {
        return $this->activeBranch->id($request->user()) ?? abort(403, 'You do not work in any campus.');
    }
}
