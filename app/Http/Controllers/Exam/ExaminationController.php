<?php

namespace App\Http\Controllers\Exam;

use App\Domain\Blueprint\BlueprintApprovers;
use App\Domain\Blueprint\Enums\BlueprintStatus;
use App\Domain\Exam\Actions\CreateExamination;
use App\Domain\Exam\Actions\DrawWholeCoursePaper;
use App\Domain\Exam\Actions\UpdateExamination;
use App\Domain\Exam\Models\Examination;
use App\Domain\Exam\Queries\ExaminationData;
use App\Domain\Exam\Queries\ExaminationList;
use App\Domain\Paper\Queries\PaperData;
use App\Http\Requests\Exam\SaveExaminationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Examinations: the list, and setting one up — what it is, when, how long, out of how many marks.
 * Every action is limited to the campus being worked in and to the courses the user's exam access
 * allows.
 */
class ExaminationController extends ExamAreaController
{
    public function index(Request $request, ExaminationList $list, ExaminationData $data): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'programme_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'year' => ['nullable', 'string', 'regex:/^\d{1,10}(-\d{1,10})?$/'],
            'exam_type_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'course_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'stage' => ['nullable', Rule::enum(BlueprintStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $branchId = $this->branchId($request);

        return Inertia::render('exams/Index', [
            'examinations' => $list->paginate($request->user('web'), $branchId, $filters),
            'stages' => $list->stageCounts($request->user('web'), $branchId),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'programme_id' => isset($filters['programme_id']) ? (int) $filters['programme_id'] : null,
                'year' => $filters['year'] ?? null,
                'exam_type_id' => isset($filters['exam_type_id']) ? (int) $filters['exam_type_id'] : null,
                'course_id' => isset($filters['course_id']) ? (int) $filters['course_id'] : null,
                'stage' => $filters['stage'] ?? '',
            ],
            'canCreate' => $request->user('web')->can('exam.create'),
            'waitingForMe' => $request->user('web')->can('exam.blueprint.approve') ? $list->awaitingApproval($request->user('web'), $branchId) : 0,
            ...$data->choices($request->user('web'), $branchId),
        ]);
    }

    public function create(Request $request, ExaminationData $data): Response
    {
        return Inertia::render('exams/ExamForm', [
            'examination' => null,
            'fixed' => false,
            ...$data->choices($request->user('web'), $this->branchId($request)),
        ]);
    }

    public function store(SaveExaminationRequest $request, CreateExamination $create, DrawWholeCoursePaper $draw): RedirectResponse
    {
        $count = $request->questionCount();

        if ($count !== null) {
            // "This many questions from the course": the examination with its published paper.
            $examination = $draw->create($request->user('web'), $this->branchId($request), $request->examinationInput(), $count);
            Inertia::flash('toast', ['type' => 'success', 'message' => __(':ref is ready: :count questions drawn and published. Now add its candidates.', ['ref' => $examination->public_ref, 'count' => $count])]);

            return to_route('conduct.candidates', $examination);
        }

        $examination = $create($request->user('web'), $this->branchId($request), $request->examinationInput());
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':ref is set up. Now plan its blueprint.', ['ref' => $examination->public_ref])]);

        return to_route('blueprints.edit', $examination);
    }

    /** The examination's page: its details and where it stands, step by step. */
    public function show(Request $request, Examination $exam, ExaminationData $data, PaperData $papers, BlueprintApprovers $approvers): Response
    {
        $this->guard($request, $exam);
        $exam->load('blueprint');
        $blueprint = $exam->blueprint ?? abort(404);

        return Inertia::render('exams/Show', [
            'examination' => $data->detail($exam),
            'can' => $data->abilities($request->user('web'), $exam, $blueprint),
            'paper' => $papers->summary($exam),
            // Whom to ask, while it waits.
            'approvers' => $blueprint->status === BlueprintStatus::Submitted ? $approvers->names($exam, $blueprint) : [],
            'canOpenPaper' => $request->user('web')->can('exam.view'),
            ...$data->blueprint($exam, $blueprint, false),
        ]);
    }

    public function edit(Request $request, Examination $exam, ExaminationData $data): Response
    {
        $this->guard($request, $exam);
        $exam->load('blueprint');
        $blueprint = $exam->blueprint ?? abort(404);
        abort_unless($data->abilities($request->user('web'), $exam, $blueprint)['edit'], 403, 'You cannot change examinations of this course.');

        return Inertia::render('exams/ExamForm', [
            'examination' => $data->detail($exam),
            // Once the blueprint is submitted, what it was checked against is fixed.
            'fixed' => $blueprint->status !== BlueprintStatus::Draft,
            ...$data->choices($request->user('web'), $this->branchId($request)),
        ]);
    }

    public function update(SaveExaminationRequest $request, Examination $exam, UpdateExamination $update): RedirectResponse
    {
        $this->guard($request, $exam);
        $update($request->user('web'), $exam, $request->examinationInput());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return to_route('exams.show', $exam);
    }
}
