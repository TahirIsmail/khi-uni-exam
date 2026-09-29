<?php

namespace App\Http\Controllers\Exam;

use App\Domain\Candidate\Models\Candidate;
use App\Domain\Exam\Models\Examination;
use App\Domain\Results\Actions\DefineResultComponents;
use App\Domain\Results\Actions\RecordComponentMark;
use App\Domain\Results\Models\ComponentMark;
use App\Domain\Results\Models\ResultComponent;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The practical, the viva and the internal assessment: the parts of a professional result this
 * system does not run and therefore cannot compute.
 *
 * One grid, candidates down and components across, because a department enters one component for a
 * whole class at a sitting — never one candidate's whole result at a time.
 */
class ComponentMarksController extends Controller
{
    public function show(Request $request, Examination $exam): Response
    {
        $components = ResultComponent::query()->where('examination_id', $exam->id)->orderBy('sort_order')->get();
        $candidates = Candidate::query()->where('examination_id', $exam->id)->orderBy('candidate_no')->get();

        $marks = ComponentMark::query()->whereIn('component_id', $components->pluck('id'))->get()
            ->groupBy('component_id')
            ->map(fn ($group) => $group->pluck('marks', 'candidate_id')->map(fn (mixed $m): float => (float) $m)->all())
            ->all();

        return Inertia::render('results/Components', [
            'examination' => [
                'id' => $exam->id,
                'reference' => $exam->public_ref,
                'title' => $exam->title,
                'totalMarks' => (float) $exam->total_marks,
                'passPercentage' => (float) $exam->pass_percentage,
            ],
            'components' => $components->map(fn (ResultComponent $c): array => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'maxMarks' => $c->max_marks,
                'group' => $c->group,
                'minPassPercentage' => $c->min_pass_percentage,
                'isPaper' => $c->isPaper(),
            ])->values()->all(),
            'candidates' => $candidates->map(fn (Candidate $c): array => [
                'id' => $c->id,
                'candidateNo' => $c->candidate_no,
                'name' => $c->name,
            ])->values()->all(),
            'marks' => $marks,
        ]);
    }

    public function define(Request $request, Examination $exam, DefineResultComponents $define): RedirectResponse
    {
        $input = $request->validate([
            'components' => ['present', 'array', 'max:10'],
            'components.*.code' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_]+$/'],
            'components.*.name' => ['required', 'string', 'max:80'],
            'components.*.max_marks' => ['required', 'numeric', 'min:0.5', 'max:9999'],
            'components.*.group' => ['required', Rule::in(['theory', 'practical'])],
            'components.*.min_pass_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $components = array_map(fn (array $c): array => [
            'code' => $c['code'],
            'name' => $c['name'],
            'max_marks' => (float) $c['max_marks'],
            'group' => $c['group'],
            'min_pass_percentage' => isset($c['min_pass_percentage']) ? (float) $c['min_pass_percentage'] : null,
        ], $input['components']);

        $define($request->user('web'), $exam, array_values($components));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved what this result is made of.')]);

        return to_route('results.components', $exam);
    }

    public function store(Request $request, Examination $exam, RecordComponentMark $record): RedirectResponse
    {
        $input = $request->validate([
            'component_id' => ['required', 'integer'],
            'marks' => ['present', 'array', 'max:500'],
            'marks.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $component = ResultComponent::query()
            ->where('examination_id', $exam->id)->whereKey($input['component_id'])->firstOrFail();

        $candidates = Candidate::query()->where('examination_id', $exam->id)
            ->whereIn('id', array_keys($input['marks']))->get()->keyBy('id');

        foreach ($input['marks'] as $candidateId => $marks) {
            // A blank box is "not entered yet", not a nil: it leaves the subject incomplete rather
            // than failing somebody who simply has not been marked.
            if ($marks === null || $marks === '') {
                continue;
            }

            $candidate = $candidates->get((int) $candidateId);
            if ($candidate === null) {
                continue;
            }

            $record($request->user('web'), $component, $candidate, (float) $marks);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':component marks saved.', ['component' => $component->name])]);

        return to_route('results.components', $exam);
    }
}
