<?php

namespace App\Http\Controllers\QuestionBank;

use App\Domain\Identity\ActiveBranch;
use App\Domain\QuestionBank\Actions\CreateQuestionDraft;
use App\Domain\QuestionBank\Actions\SaveQuestionDraft;
use App\Domain\QuestionBank\Actions\StartNewVersion;
use App\Domain\QuestionBank\Actions\SubmitQuestionVersion;
use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Queries\QuestionEditorData;
use App\Domain\QuestionBank\Queries\QuestionExport;
use App\Domain\QuestionBank\Queries\QuestionHistory;
use App\Domain\QuestionBank\Queries\QuestionList;
use App\Domain\QuestionBank\Queries\VersionDiff;
use App\Domain\QuestionBank\Validation\QuestionValidator;
use App\Domain\QuestionBank\Validation\VersionContentReader;
use App\Http\Controllers\Controller;
use App\Http\Requests\QuestionBank\SaveQuestionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * The filters the search screen and the export both accept.
     *
     * @return array<string, mixed>
     */
    private static function filterRules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(array_keys(VersionStatus::groups()))],
            'programme_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'year' => ['nullable', 'string', 'regex:/^\d{1,10}(-\d{1,10})?$/'],
            'exam_type_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'used' => ['nullable', Rule::in(['used', 'unused'])],
            'used_from' => ['nullable', 'date_format:Y-m-d'],
            'used_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:used_from'],
            'course_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'node_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'discipline_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'type_id' => ['nullable', 'integer', Rule::exists('qb_question_types', 'id')],
            'cognitive_level_id' => ['nullable', 'integer', Rule::exists('qb_cognitive_levels', 'id')],
            'difficulty_level_id' => ['nullable', 'integer', Rule::exists('qb_difficulty_levels', 'id')],
            'tag_id' => ['nullable', 'integer', Rule::exists('qb_tags', 'id')],
            'author_id' => ['nullable', 'integer', 'min:1'],
            'marks_min' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'marks_max' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'updated_from' => ['nullable', 'date_format:Y-m-d'],
            'updated_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:updated_from'],
            'sort' => ['nullable', Rule::in(['relevance', 'updated', 'oldest', 'marks', 'reference'])],
            'mine' => ['nullable', 'boolean'],
            'duplicates' => ['nullable', 'boolean'],
            'archived' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function index(Request $request, QuestionList $list): Response
    {
        $filters = $request->validate(self::filterRules());

        $filters['mine'] = $request->boolean('mine');
        $filters['duplicates'] = $request->boolean('duplicates');
        $filters['archived'] = $request->boolean('archived');
        $branchId = $this->branchId($request);
        $number = fn (string $key): ?int => isset($filters[$key]) ? (int) $filters[$key] : null;

        return Inertia::render('qbank/Questions', [
            'questions' => $list->paginate($request->user(), $branchId, $filters),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
                'programme_id' => $number('programme_id'),
                'year' => $filters['year'] ?? null,
                'exam_type_id' => $number('exam_type_id'),
                'used' => $filters['used'] ?? '',
                'used_from' => $filters['used_from'] ?? null,
                'used_to' => $filters['used_to'] ?? null,
                'course_id' => $number('course_id'),
                'node_id' => $number('node_id'),
                'discipline_id' => $number('discipline_id'),
                'type_id' => $number('type_id'),
                'cognitive_level_id' => $number('cognitive_level_id'),
                'difficulty_level_id' => $number('difficulty_level_id'),
                'tag_id' => $number('tag_id'),
                'author_id' => $number('author_id'),
                'marks_min' => $filters['marks_min'] ?? null,
                'marks_max' => $filters['marks_max'] ?? null,
                'updated_from' => $filters['updated_from'] ?? null,
                'updated_to' => $filters['updated_to'] ?? null,
                'sort' => $filters['sort'] ?? 'relevance',
                'mine' => $filters['mine'],
                'duplicates' => $filters['duplicates'],
                'archived' => $filters['archived'],
            ],
            'statuses' => $list->statusGroupCounts($request->user(), $branchId),
            'authors' => $list->authors($request->user(), $branchId),
            ...$this->editorData->forSearch($request->user(), $branchId),
            'canCreate' => $request->user()->can('qbank.question.create'),
            'canExport' => $request->user()->can('qbank.question.export'),
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

    public function edit(Request $request, Question $question, QuestionVersion $version): Response|RedirectResponse
    {
        $this->authoriseVersion($request, $question, $version);

        // Once it has been sent for review its content is fixed, so the editor is not offered at
        // all; changing it means a new version. The action behind the form refuses it as well.
        if (! $version->isEditable()) {
            return to_route('questions.show', [$question->id, $version->id]);
        }

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

    /**
     * The search results as a spreadsheet, answer keys included. Its own permission, because the
     * file leaves the system; the campus and the user's exam access still decide what is in it.
     */
    public function export(Request $request, QuestionExport $export): StreamedResponse
    {
        $filters = $request->validate(self::filterRules());
        $filters['mine'] = $request->boolean('mine');
        $filters['duplicates'] = $request->boolean('duplicates');
        $filters['archived'] = $request->boolean('archived');

        return $export->stream($request->user(), $this->branchId($request), $filters);
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

    /** Everything that happened to one question: its versions and their steps. */
    public function history(Request $request, Question $question, QuestionHistory $history): Response
    {
        abort_unless((int) $question->branch_id === $this->branchId($request), 404);
        abort_unless($this->editorData->allowsQuestion($request->user(), 'qbank.question.view', $question), 403);

        return Inertia::render('qbank/QuestionHistory', $history->for($question, $request->user()));
    }

    /** Two versions side by side. */
    public function diff(Request $request, Question $question, VersionDiff $diff): Response
    {
        abort_unless((int) $question->branch_id === $this->branchId($request), 404);
        abort_unless($this->editorData->allowsQuestion($request->user(), 'qbank.question.view', $question), 403);

        $input = $request->validate([
            'from' => ['required', 'integer', 'min:1'],
            'to' => ['required', 'integer', 'min:1'],
        ]);

        $from = $question->versions()->whereKey((int) $input['from'])->firstOrFail();
        $to = $question->versions()->whereKey((int) $input['to'])->firstOrFail();

        return Inertia::render('qbank/QuestionDiff', [
            'reference' => $question->public_ref,
            'questionId' => $question->id,
            'diff' => $diff->between($from, $to),
            'versions' => $question->versions()->orderByDesc('version_no')->get(['id', 'version_no', 'status'])
                ->map(fn (QuestionVersion $version): array => [
                    'id' => $version->id,
                    'versionNo' => $version->version_no,
                    'statusLabel' => $version->status->label(),
                ])->values()->all(),
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
