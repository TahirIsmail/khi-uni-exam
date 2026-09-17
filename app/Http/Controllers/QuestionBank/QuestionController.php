<?php

namespace App\Http\Controllers\QuestionBank;

use App\Domain\Identity\ActiveBranch;
use App\Domain\QuestionBank\Actions\CreateQuestionDraft;
use App\Domain\QuestionBank\Actions\SaveQuestionDraft;
use App\Domain\QuestionBank\Actions\StartNewVersion;
use App\Domain\QuestionBank\Actions\SubmitQuestionVersion;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Queries\QuestionEditorData;
use App\Domain\QuestionBank\Queries\QuestionList;
use App\Domain\QuestionBank\Validation\QuestionValidator;
use App\Domain\QuestionBank\Validation\VersionContentReader;
use App\Http\Controllers\Controller;
use App\Http\Requests\QuestionBank\SaveQuestionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Writing questions: the list of questions in the campus, the editor, and sending a draft for
 * review. Every action is limited to the campus being worked in and to the courses the user's
 * exam access allows.
 */
class QuestionController extends Controller
{
    public function __construct(
        private readonly ActiveBranch $activeBranch,
        private readonly QuestionEditorData $editorData,
    ) {}

    public function index(Request $request, QuestionList $list): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:20'],
            'course_id' => ['nullable', 'integer', 'min:1'],
            'mine' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $filters['mine'] = $request->boolean('mine');

        return Inertia::render('qbank/Questions', [
            'questions' => $list->paginate($request->user(), $this->branchId($request), $filters),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
                'course_id' => isset($filters['course_id']) ? (int) $filters['course_id'] : null,
                'mine' => $filters['mine'],
            ],
            'courses' => $this->editorData->courses($request->user(), $this->branchId($request)),
            'statuses' => $list->statusCounts($request->user(), $this->branchId($request)),
            'canCreate' => $request->user()->can('qbank.question.create'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('qbank/QuestionEditor', [
            'version' => null,
            'reference' => null,
            ...$this->editorData->forCreate($request->user(), $this->branchId($request)),
        ]);
    }

    public function store(SaveQuestionRequest $request, CreateQuestionDraft $create): RedirectResponse
    {
        $version = $create($request->user(), $this->branchId($request), $request->content());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft saved as :ref.', ['ref' => $version->question->public_ref])]);

        return to_route('questions.edit', [$version->question_id, $version->id]);
    }

    public function edit(Request $request, Question $question, QuestionVersion $version): Response
    {
        $this->authoriseVersion($request, $question, $version);

        return Inertia::render('qbank/QuestionEditor', [
            'version' => $this->editorData->version($version),
            'reference' => $question->public_ref,
            ...$this->editorData->forCreate($request->user(), $this->branchId($request)),
        ]);
    }

    public function update(SaveQuestionRequest $request, Question $question, QuestionVersion $version, SaveQuestionDraft $save): RedirectResponse
    {
        $this->authoriseVersion($request, $question, $version);
        $save($request->user(), $version, $request->content());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft saved.')]);

        return back();
    }

    public function submit(Request $request, Question $question, QuestionVersion $version, SubmitQuestionVersion $submit): RedirectResponse
    {
        $this->authoriseVersion($request, $question, $version);
        $input = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $submit($request->user(), $version, $input['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sent for review.')]);

        return to_route('questions.index');
    }

    public function newVersion(Request $request, Question $question, StartNewVersion $start): RedirectResponse
    {
        abort_unless((int) $question->branch_id === $this->branchId($request), 404);

        $version = $start($request->user(), $question);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Version :no started as a draft.', ['no' => $version->version_no])]);

        return to_route('questions.edit', [$question->id, $version->id]);
    }

    /** Live checks while the author types: the same rules that submission applies. */
    public function check(SaveQuestionRequest $request, QuestionValidator $validator): JsonResponse
    {
        return response()->json($validator->check($request->content()));
    }

    /** The topics of a course, for the taxonomy picker. */
    public function curriculum(Request $request): JsonResponse
    {
        $input = $request->validate(['course_id' => ['required', 'integer', 'min:1', 'max:4294967295']]);
        $courseId = (int) $input['course_id'];

        $allowed = collect($this->editorData->courses($request->user(), $this->branchId($request)))->contains('id', $courseId);
        abort_unless($allowed, 403, 'That course is not in your campus or exam access.');

        return response()->json(['nodes' => $this->editorData->curriculum($courseId)]);
    }

    public function show(Request $request, Question $question, QuestionVersion $version, VersionContentReader $reader, QuestionValidator $validator): Response
    {
        $this->authoriseVersion($request, $question, $version, view: true);

        return Inertia::render('qbank/QuestionPreview', [
            'reference' => $question->public_ref,
            'version' => $this->editorData->version($version),
            'checks' => $validator->check($reader->read($version)),
            ...$this->editorData->lookups(),
        ]);
    }

    private function authoriseVersion(Request $request, Question $question, QuestionVersion $version, bool $view = false): void
    {
        abort_unless((int) $version->question_id === $question->id, 404);
        abort_unless((int) $question->branch_id === $this->branchId($request), 404);

        $permission = $view ? 'qbank.question.view' : ($version->author_id === $request->user()->id ? 'qbank.question.edit_own' : 'qbank.question.edit_any');
        abort_unless($this->editorData->allows($request->user(), $permission, $version), 403);
    }

    private function branchId(Request $request): int
    {
        return $this->activeBranch->id($request->user()) ?? abort(403, 'You do not work in any campus.');
    }
}
