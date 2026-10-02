<?php

namespace App\Http\Controllers\QuestionBank;

use App\Domain\Identity\ActiveBranch;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Review\ActivateVersion;
use App\Domain\QuestionBank\Review\ApproveVersion;
use App\Domain\QuestionBank\Review\ConsolidatedPrehoc;
use App\Domain\QuestionBank\Review\DecideOnVersion;
use App\Domain\QuestionBank\Review\RejectVersion;
use App\Domain\QuestionBank\Review\ReviewBoard;
use App\Http\Controllers\Controller;
use App\Support\Cms\CmsSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Approving questions: the queue of questions that have been reviewed, and the three decisions an
 * approver can make — approve (with the consolidated pre-hoc values), put an approved question into
 * use, or turn it down with a reason. An approver never approves their own question.
 */
class ApprovalController extends Controller
{
    public function __construct(
        private readonly ActiveBranch $activeBranch,
        private readonly ReviewBoard $board,
    ) {}

    public function index(Request $request, CmsSettings $settings): Response
    {
        $input = $request->validate(['show' => ['nullable', 'in:ready,waiting,approved,all']]);
        $show = $input['show'] ?? 'ready';

        return Inertia::render('qbank/ApprovalQueue', [
            'versions' => $this->board->approvalQueue($request->user('web'), $this->branchId($request), $show),
            'show' => $show,
            // Off in kmu-cms: the department / subject review is the only level.
            'academicReview' => $settings->academicReview(),
        ]);
    }

    public function approve(Request $request, Question $question, QuestionVersion $version, ApproveVersion $approve): RedirectResponse
    {
        $this->authoriseVersion($request, $question, $version);

        $input = $request->validate([
            'decision_id' => ['required', 'integer', 'min:1', 'max:255'],
            'cognitive_level_id' => ['nullable', 'integer', 'min:1', 'max:255'],
            'difficulty_level_id' => ['nullable', 'integer', 'min:1', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $approved = $approve($request->user('web'), $version, new ConsolidatedPrehoc(
            decisionId: (int) $input['decision_id'],
            cognitiveLevelId: isset($input['cognitive_level_id']) ? (int) $input['cognitive_level_id'] : null,
            difficultyLevelId: isset($input['difficulty_level_id']) ? (int) $input['difficulty_level_id'] : null,
            reason: $input['reason'] ?? null,
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => $approved->status->isUsableInExams()
            ? __('Approved and in use.')
            : __('Approved. Put it into use when you are ready.')]);

        return to_route('approvals.index');
    }

    /**
     * The approving authority's decision, one of KMU's five: Accept or Retain in QBank store it,
     * Review sends it round again, Revise sends it back to its author, Remove / Discard archives it.
     */
    public function decide(Request $request, Question $question, QuestionVersion $version, DecideOnVersion $decide): RedirectResponse
    {
        $this->authoriseVersion($request, $question, $version);

        $input = $request->validate([
            'decision_id' => ['required', 'integer', 'min:1', 'max:255'],
            'cognitive_level_id' => ['nullable', 'integer', 'min:1', 'max:255'],
            'difficulty_level_id' => ['nullable', 'integer', 'min:1', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $decided = $decide($request->user('web'), $version, new ConsolidatedPrehoc(
            decisionId: (int) $input['decision_id'],
            cognitiveLevelId: isset($input['cognitive_level_id']) ? (int) $input['cognitive_level_id'] : null,
            difficultyLevelId: isset($input['difficulty_level_id']) ? (int) $input['difficulty_level_id'] : null,
            reason: $input['reason'] ?? null,
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($decided->kmuStatus()) {
            'Accept', 'Retain in QBank' => __('Stored in the QBank as :status.', ['status' => $decided->kmuStatus()]),
            'Revise' => __('Sent back to the author to revise.'),
            'Review' => __('Sent for another round of review.'),
            default => __('Removed / discarded.'),
        }]);

        return to_route('approvals.index');
    }

    public function activate(Request $request, Question $question, QuestionVersion $version, ActivateVersion $activate): RedirectResponse
    {
        $this->authoriseVersion($request, $question, $version);

        $input = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $activate($request->user('web'), $version, $input['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('It can now be used in examinations.')]);

        return back();
    }

    public function reject(Request $request, Question $question, QuestionVersion $version, RejectVersion $reject): RedirectResponse
    {
        $this->authoriseVersion($request, $question, $version);

        $input = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $reject($request->user('web'), $version, (string) $input['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The question was turned down and archived.')]);

        return to_route('approvals.index');
    }

    private function authoriseVersion(Request $request, Question $question, QuestionVersion $version): void
    {
        abort_unless((int) $version->question_id === $question->id, 404);
        abort_unless((int) $question->branch_id === $this->branchId($request), 404);
    }

    private function branchId(Request $request): int
    {
        return $this->activeBranch->id($request->user('web')) ?? abort(403, 'You do not work in any campus.');
    }
}
