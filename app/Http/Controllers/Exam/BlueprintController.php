<?php

namespace App\Http\Controllers\Exam;

use App\Domain\Blueprint\Actions\BlueprintWorkflow;
use App\Domain\Blueprint\Actions\SaveBlueprint;
use App\Domain\Exam\Models\Examination;
use App\Domain\Exam\Queries\ExaminationData;
use App\Http\Requests\Exam\SaveBlueprintRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The blueprint (Table of Specification) of an examination: writing it, submitting it, and its
 * approval. Each action checks its own right, the campus and the course again.
 */
class BlueprintController extends ExamAreaController
{
    public function edit(Request $request, Examination $exam, ExaminationData $data): Response
    {
        $this->guard($request, $exam);
        $exam->load('blueprint');
        $blueprint = $exam->blueprint ?? abort(404);
        $can = $data->abilities($request->user('web'), $exam, $blueprint);

        return Inertia::render('exams/Blueprint', [
            'examination' => $data->detail($exam),
            'can' => $can,
            ...$data->blueprint($exam, $blueprint, $can['editBlueprint']),
        ]);
    }

    public function update(SaveBlueprintRequest $request, Examination $exam, SaveBlueprint $save): RedirectResponse
    {
        $this->guard($request, $exam);
        $save($request->user('web'), $exam, $request->blueprintInput());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Blueprint saved.')]);

        return to_route('blueprints.edit', $exam);
    }

    public function submit(Request $request, Examination $exam, BlueprintWorkflow $workflow): RedirectResponse
    {
        $this->guard($request, $exam);
        $workflow->submit($request->user('web'), $exam);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Submitted for approval.')]);

        return to_route('exams.show', $exam);
    }

    public function approve(Request $request, Examination $exam, BlueprintWorkflow $workflow): RedirectResponse
    {
        $this->guard($request, $exam);
        $workflow->approve($request->user('web'), $exam);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Blueprint approved. The paper can be built next.')]);

        return to_route('exams.show', $exam);
    }

    public function sendBack(Request $request, Examination $exam, BlueprintWorkflow $workflow): RedirectResponse
    {
        $this->guard($request, $exam);
        $input = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $workflow->returnToDraft($request->user('web'), $exam, (string) $input['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sent back with your reason.')]);

        return to_route('exams.show', $exam);
    }

    public function reopen(Request $request, Examination $exam, BlueprintWorkflow $workflow): RedirectResponse
    {
        $this->guard($request, $exam);
        $input = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $workflow->reopen($request->user('web'), $exam, (string) $input['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The blueprint is back in preparation.')]);

        return to_route('exams.show', $exam);
    }
}
