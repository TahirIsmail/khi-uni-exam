<?php

namespace App\Http\Controllers\Exam;

use App\Domain\Exam\Models\Examination;
use App\Domain\Identity\ActiveBranch;
use App\Domain\Paper\Models\PaperItem;
use App\Domain\Results\Actions\ApproveResults;
use App\Domain\Results\Actions\PublishResults;
use App\Domain\Results\Actions\RekeyPaperItem;
use App\Domain\Results\Enums\RekeyDecision;
use App\Domain\Results\Queries\ResultsData;
use App\Domain\Results\Queries\ResultsExaminationList;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Results (exam phase, step 21): raw scores until the pass percentage is applied, a controller
 * approves them, and they are published — three separate rights.
 */
class ResultsController extends Controller
{
    public function __construct(private readonly ActiveBranch $activeBranch) {}

    public function index(Request $request, ResultsExaminationList $list): Response
    {
        return Inertia::render('results/Index', [
            'examinations' => $list->forBranch($this->branchId($request)),
        ]);
    }

    public function show(Request $request, Examination $exam, ResultsData $data): Response
    {
        $this->guard($request, $exam);
        $user = $request->user('web');

        return Inertia::render('results/Show', [
            'examination' => ['id' => $exam->id, 'reference' => $exam->public_ref, 'title' => $exam->title],
            ...$data->forExamination($exam),
            'can' => [
                'approve' => $user->can('result.approve'),
                'publish' => $user->can('result.publish'),
                'rescore' => $user->can('result.rescore'),
                'analytics' => $user->can('analytics.view') || $user->can('analytics.run') || $user->can('analytics.decision.record'),
            ],
        ]);
    }

    public function approve(Request $request, Examination $exam, ApproveResults $approve): RedirectResponse
    {
        $this->guard($request, $exam);
        $approve($request->user('web'), $exam);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Results approved.')]);

        return to_route('results.show', $exam);
    }

    public function publish(Request $request, Examination $exam, PublishResults $publish): RedirectResponse
    {
        $this->guard($request, $exam);
        $publish($request->user('web'), $exam);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Results published.')]);

        return to_route('results.show', $exam);
    }

    public function rekey(Request $request, Examination $exam, PaperItem $item, RekeyPaperItem $rekey): RedirectResponse
    {
        $this->guard($request, $exam);
        abort_unless($item->paper->examination_id === $exam->id, 404);

        $input = $request->validate([
            'decision' => ['required', Rule::enum(RekeyDecision::class)],
            'corrected_option_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $rekey(
            $request->user('web'),
            $item,
            RekeyDecision::from($input['decision']),
            isset($input['corrected_option_id']) ? (int) $input['corrected_option_id'] : null,
            $input['reason'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Item re-keyed and affected results rescored.')]);

        return to_route('results.show', $exam);
    }

    private function branchId(Request $request): int
    {
        return $this->activeBranch->id($request->user('web')) ?? abort(403, 'You do not work in any campus.');
    }

    private function guard(Request $request, Examination $examination): void
    {
        abort_unless($examination->branch_id === $this->branchId($request), 404);
        $user = $request->user('web');
        abort_unless($user->can('result.view') || $user->can('result.approve') || $user->can('result.publish') || $user->can('result.rescore'), 403, 'You cannot open results for this course.');
    }
}
