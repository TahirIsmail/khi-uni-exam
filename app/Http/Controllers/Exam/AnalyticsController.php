<?php

namespace App\Http\Controllers\Exam;

use App\Domain\Analytics\Actions\RecordPosthocDecision;
use App\Domain\Analytics\Actions\RunAnalysis;
use App\Domain\Analytics\Queries\ItemAnalysis;
use App\Domain\Analytics\Queries\Reliability;
use App\Domain\Analytics\Queries\TosCompliance;
use App\Domain\Exam\Models\Examination;
use App\Domain\Identity\ActiveBranch;
use App\Domain\QuestionBank\Models\PosthocDecisionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Post-hoc analysis (exam phase, step 22): item statistics, reliability, and compliance with the
 * table of specification, for one examination — and the decision that goes back to the question
 * bank once there are statistics to decide from.
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly ActiveBranch $activeBranch) {}

    public function show(Request $request, Examination $exam, ItemAnalysis $itemAnalysis, Reliability $reliability, TosCompliance $tos): Response
    {
        $this->guard($request, $exam);
        $user = $request->user('web');

        return Inertia::render('results/Analysis', [
            'examination' => ['id' => $exam->id, 'reference' => $exam->public_ref, 'title' => $exam->title],
            'items' => $itemAnalysis->forExamination($exam),
            'reliability' => $reliability->forExamination($exam),
            'tos' => $tos->forExamination($exam),
            'decisionTypes' => PosthocDecisionType::query()->where('is_active', true)->orderBy('sort_order')
                ->get(['code', 'name', 'description'])->values()->all(),
            'can' => [
                'run' => $user->can('analytics.run'),
                'decide' => $user->can('analytics.decision.record'),
            ],
        ]);
    }

    public function run(Request $request, Examination $exam, RunAnalysis $run): RedirectResponse
    {
        $this->guard($request, $exam);
        $run($request->user('web'), $exam);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Analysis run.')]);

        return to_route('results.analysis', $exam);
    }

    public function decide(Request $request, Examination $exam, QuestionVersion $version, RecordPosthocDecision $decide): RedirectResponse
    {
        $this->guard($request, $exam);

        $input = $request->validate([
            'decision' => ['required', 'string', 'max:30'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $decide($request->user('web'), $version, $exam, $input['decision'], $input['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Decision recorded.')]);

        return to_route('results.analysis', $exam);
    }

    private function branchId(Request $request): int
    {
        return $this->activeBranch->id($request->user('web')) ?? abort(403, 'You do not work in any campus.');
    }

    private function guard(Request $request, Examination $examination): void
    {
        abort_unless($examination->branch_id === $this->branchId($request), 404);
        $user = $request->user('web');
        abort_unless($user->can('analytics.view') || $user->can('analytics.run') || $user->can('analytics.decision.record'), 403, 'You cannot open analysis for this course.');
    }
}
