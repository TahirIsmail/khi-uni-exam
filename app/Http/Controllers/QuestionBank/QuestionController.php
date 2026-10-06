<?php

namespace App\Http\Controllers\QuestionBank;

use App\Domain\Identity\ActiveBranch;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\QuestionBank\Actions\CreateQuestionDraft;
use App\Domain\QuestionBank\Actions\SaveQuestionDraft;
use App\Domain\QuestionBank\Actions\StartNewVersion;
use App\Domain\QuestionBank\Actions\SubmitQuestionVersion;
use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\PrehocDecision;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\ReviewAssignment;
use App\Domain\QuestionBank\Queries\QuestionEditorData;
use App\Domain\QuestionBank\Queries\QuestionExport;
use App\Domain\QuestionBank\Queries\QuestionHistory;
use App\Domain\QuestionBank\Queries\QuestionList;
use App\Domain\QuestionBank\Queries\VersionDiff;
use App\Domain\QuestionBank\Review\ChooseReviewers;
use App\Domain\QuestionBank\Review\ConsolidatedPrehoc;
use App\Domain\QuestionBank\Review\DecideOnVersion;
use App\Domain\QuestionBank\Review\RejectVersion;
use App\Domain\QuestionBank\Review\ReviewerPool;
use App\Domain\QuestionBank\Review\ReviewStage;
use App\Domain\QuestionBank\Validation\QuestionValidator;
use App\Domain\QuestionBank\Validation\VersionContentReader;
use App\Http\Controllers\Controller;
use App\Http\Controllers\QuestionBank\Concerns\FollowsTheList;
use App\Http\Requests\QuestionBank\SaveQuestionRequest;
use App\Support\Cms\CmsAcademic;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
    use FollowsTheList;

    public function __construct(
        private readonly ActiveBranch $activeBranch,
        private readonly QuestionEditorData $editorData,
    ) {}

    /**
     * The filters the search screen and the export both accept.
     *
     * @return array<string, mixed>
     */
    public static function filterRules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            // "all" is the All button; with no status at all the list shows every status as well.
            'status' => ['nullable', 'string', Rule::in(['all', ...array_keys(VersionStatus::groups())])],
            'programme_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'year' => ['nullable', 'string', 'regex:/^\d{1,10}(-\d{1,10})?$/'],
            'exam_type_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'intake_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
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

        // KMU: the question bank opens on what is stored in it (Accept); All shows everything.
        if ($request->query() === []) {
            $filters['status'] = 'accept';
        }
        if (($filters['status'] ?? null) === 'all') {
            unset($filters['status']);
        }
        $filters['mine'] = $request->boolean('mine');
        $filters['duplicates'] = $request->boolean('duplicates');
        $filters['archived'] = $request->boolean('archived');
        $branchId = $this->branchId($request);
        $number = fn (string $key): ?int => isset($filters[$key]) ? (int) $filters[$key] : null;

        return Inertia::render('qbank/Questions', [
            'questions' => $list->paginate($request->user('web'), $branchId, $filters),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
                'programme_id' => $number('programme_id'),
                'year' => $filters['year'] ?? null,
                'exam_type_id' => $number('exam_type_id'),
                'intake_id' => $number('intake_id'),
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
            'statuses' => $list->statusGroupCounts($request->user('web'), $branchId),
            'authors' => $list->authors($request->user('web'), $branchId),
            ...$this->editorData->forSearch($request->user('web'), $branchId),
            'canCreate' => $request->user('web')->can('qbank.question.create'),
            'canExport' => $request->user('web')->can('qbank.question.export'),
            'canEditOwn' => $request->user('web')->can('qbank.question.edit_own'),
            'canEditAny' => $request->user('web')->can('qbank.question.edit_any'),
            'canApprove' => $request->user('web')->can('qbank.question.approve'),
            // kmu-cms: Question Bank → Questions → Delete.
            'canDelete' => $request->user('web')->can('qbank.question.archive'),
            // kmu-cms: Question Bank & Exams → Advanced Question Filters.
            'canFilter' => $request->user('web')->can('qbank.question.filter'),
        ]);
    }

    /**
     * One decision for several questions ticked in the list: Accept, Retain in QBank, Revise or
     * Remove / Discard. Each question is checked on its own — campus, rights, its step — and the
     * ones that cannot take the decision are skipped and named, the others go ahead.
     */
    public function bulk(Request $request, DecideOnVersion $decide, RejectVersion $reject): RedirectResponse
    {
        $input = $request->validate([
            'action' => ['required', 'in:accept,retain,revise,remove'],
            'version_ids' => ['required', 'array', 'min:1', 'max:100'],
            'version_ids.*' => ['integer', 'min:1'],
            // Remove / Discard needs no reason (KMU); Revise has to tell the authors what to change.
            'reason' => ['nullable', 'required_if:action,revise', 'string', Rule::when($request->input('action') === 'revise', 'min:10'), 'max:500'],
        ], [
            'reason.required_if' => 'Say why (at least 10 characters); the author will see it.',
            'reason.min' => 'Say why (at least 10 characters); the author will see it.',
        ]);

        $user = $request->user('web');
        $action = (string) $input['action'];
        $reason = isset($input['reason']) ? trim((string) $input['reason']) : null;
        $decisionId = (int) PrehocDecision::query()->where('code', $action)->where('is_active', true)->value('id');

        $versions = QuestionVersion::query()
            ->where('branch_id', $this->branchId($request))
            ->whereIn('id', array_map('intval', $input['version_ids']))
            ->with('question')
            ->orderBy('id')
            ->get();

        $done = 0;
        $skipped = [];
        foreach ($versions as $version) {
            try {
                if ($action === 'remove') {
                    $reject($user, $version, $reason === '' ? null : $reason);
                } else {
                    $decide($user, $version, new ConsolidatedPrehoc(decisionId: $decisionId, reason: $reason));
                }
                $done++;
            } catch (ValidationException $e) {
                $skipped[] = $version->question->public_ref.': '.collect($e->errors())->flatten()->first();
            } catch (AuthorizationException $e) {
                $skipped[] = $version->question->public_ref.': '.$e->getMessage();
            }
        }

        $message = trans_choice('{0} Nothing was changed.|{1} 1 question done.|[2,*] :count questions done.', $done, ['count' => $done]);
        if ($skipped !== []) {
            $message .= ' '.__('Skipped: :list', ['list' => implode(' · ', $skipped)]);
        }
        Inertia::flash('toast', ['type' => $done > 0 ? 'success' : 'error', 'message' => $message]);

        return back();
    }

    public function create(Request $request): Response
    {
        // "Save & new" opens the next question where the last one was filed (ids only;
        // the editor checks each one against its own lists, and the actions check them again).
        $prefill = $request->validate([
            'course_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'node_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'exam_type_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'intake_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'question_type_id' => ['nullable', 'integer', 'min:1', 'max:255'],
        ]);

        return Inertia::render('qbank/QuestionEditor', [
            'version' => null,
            'reference' => null,
            'prefill' => array_map('intval', array_filter($prefill, fn (mixed $value): bool => $value !== null)),
            'can' => ['edit' => true, 'submit' => $request->user('web')->can('qbank.question.submit'), 'newVersion' => false],
            ...$this->editorData->forCreate($request->user('web'), $this->branchId($request)),
        ]);
    }

    public function store(SaveQuestionRequest $request, CreateQuestionDraft $create, SubmitQuestionVersion $submit, ChooseReviewers $choose): RedirectResponse
    {
        $version = $create($request->user('web'), $this->branchId($request), $request->content());
        $this->chooseReviewers($request, $version, $choose);

        // "Send for review" on a question not saved yet: it is saved, then sent.
        if ($request->input('then') === 'submit') {
            return $this->sendAfterSaving($request, $version, $submit);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft saved as :ref.', ['ref' => $version->question->public_ref])]);

        // "Save & new": straight on to the next question, filed in the same place.
        if ($request->input('then') === 'new') {
            return to_route('questions.create', array_filter([
                'course_id' => $version->course_id,
                'node_id' => $version->node_id,
                'exam_type_id' => $version->exam_type_id,
                'intake_id' => $version->intake_id,
                'question_type_id' => $version->question_type_id,
            ], fn (mixed $value): bool => $value !== null));
        }

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
            'can' => $this->editorData->abilities($request->user('web'), $version),
            'neighbours' => $this->listNeighbours($request, $version),
            ...$this->editorData->forCreate($request->user('web'), $this->branchId($request)),
        ]);
    }

    public function update(SaveQuestionRequest $request, Question $question, QuestionVersion $version, SaveQuestionDraft $save, SubmitQuestionVersion $submit, ChooseReviewers $choose): RedirectResponse
    {
        $this->authoriseVersion($request, $question, $version);
        $save($request->user('web'), $version, $request->content());
        $this->chooseReviewers($request, $version->fresh() ?? $version, $choose);

        // "Send for review": what is on the screen is saved first, so what goes for review is what
        // the author sees (and what the live checks passed), not an older saved draft.
        if ($request->input('then') === 'submit') {
            return $this->sendAfterSaving($request, $version->fresh() ?? $version, $submit);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft saved.')]);

        return back();
    }

    public function submit(Request $request, Question $question, QuestionVersion $version, SubmitQuestionVersion $submit): RedirectResponse
    {
        $this->authoriseVersion($request, $question, $version);
        $input = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $submit($request->user('web'), $version, $input['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sent for review.')]);

        return to_route('questions.index');
    }

    /**
     * The reviewers the author asked for, kept with the draft (only when the editor sent the
     * choice, so an older client leaves an earlier choice as it was).
     */
    private function chooseReviewers(Request $request, QuestionVersion $version, ChooseReviewers $choose): void
    {
        if (! $request->has('subject_reviewer_id') && ! $request->has('academic_reviewer_id')) {
            return;
        }

        $id = fn (string $key): ?int => $request->filled($key) ? (int) $request->input($key) : null;
        $choose($version, $id('subject_reviewer_id'), $id('academic_reviewer_id'));
    }

    /**
     * Who may review questions of a course, at each level, for the author choosing while writing.
     */
    public function reviewers(Request $request, ReviewerPool $pool, CmsAcademic $academic): JsonResponse
    {
        $input = $request->validate(['course_id' => ['required', 'integer', 'min:1', 'max:4294967295']]);
        $courseId = (int) $input['course_id'];

        $allowed = collect($this->editorData->courses($request->user('web'), $this->branchId($request)))->contains('id', $courseId);
        abort_unless($allowed, 403, 'That course is not in your campus or exam access.');

        $place = $academic->placeOfCourse($courseId);
        abort_if($place === null, 404);
        $target = new ScopeTarget($place['branch_id'], $place['programme_id'], $place['professional_id'], $courseId);

        $list = fn (ReviewStage $stage): array => array_map(fn (array $row): array => [
            'id' => $row['user']->id,
            'name' => $row['user']->name,
            'openLoad' => $row['openLoad'],
        ], $pool->forTarget($target, $stage, $request->user('web')->id));

        return response()->json(['subject' => $list(ReviewStage::Subject), 'academic' => $list(ReviewStage::Academic)]);
    }

    /**
     * Sends a just-saved draft for review. When something still stops it, the draft stays saved and
     * the editor opens on it with the reasons, so nothing the author wrote is lost.
     */
    private function sendAfterSaving(Request $request, QuestionVersion $version, SubmitQuestionVersion $submit): RedirectResponse
    {
        try {
            $submit($request->user('web'), $version);
        } catch (ValidationException $problem) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Draft saved, but it cannot be sent for review yet.')]);

            return to_route('questions.edit', [$version->question_id, $version->id])->withErrors($problem->errors());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved and sent for review as :ref.', ['ref' => $version->question->public_ref])]);

        return to_route('questions.index');
    }

    public function newVersion(Request $request, Question $question, StartNewVersion $start): RedirectResponse
    {
        abort_unless((int) $question->branch_id === $this->branchId($request), 404);

        $version = $start($request->user('web'), $question);

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
        if (($filters['status'] ?? null) === 'all') {
            unset($filters['status']);
        }
        $filters['mine'] = $request->boolean('mine');
        $filters['duplicates'] = $request->boolean('duplicates');
        $filters['archived'] = $request->boolean('archived');

        return $export->stream($request->user('web'), $this->branchId($request), $filters);
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

        $allowed = collect($this->editorData->courses($request->user('web'), $this->branchId($request)))->contains('id', $courseId);
        abort_unless($allowed, 403, 'That course is not in your campus or exam access.');

        return response()->json(['nodes' => $this->editorData->curriculum($courseId)]);
    }

    public function show(Request $request, Question $question, QuestionVersion $version, VersionContentReader $reader, QuestionValidator $validator): Response
    {
        $this->authoriseVersion($request, $question, $version, view: true);

        return Inertia::render('qbank/QuestionPreview', [
            'reference' => $question->public_ref,
            'version' => $this->editorData->version($version),
            'can' => $this->editorData->abilities($request->user('web'), $version),
            'reviewHere' => $this->hasReviewWork($request, $version),
            'neighbours' => $this->listNeighbours($request, $version),
            'checks' => $validator->check($reader->read($version)),
            ...$this->editorData->lookups(),
        ]);
    }

    /** Everything that happened to one question: its versions and their steps. */
    public function history(Request $request, Question $question, QuestionHistory $history): Response
    {
        abort_unless((int) $question->branch_id === $this->branchId($request), 404);
        abort_unless($this->editorData->allowsQuestion($request->user('web'), 'qbank.question.view', $question), 403);

        $latest = $question->versions()->orderByDesc('version_no')->firstOrFail();

        return Inertia::render('qbank/QuestionHistory', [
            ...$history->for($question, $request->user('web')),
            'can' => $this->editorData->abilities($request->user('web'), $latest),
            // The list shows each question's newest version, so that is where this one stands in it.
            'neighbours' => $this->listNeighbours($request, $latest, history: true),
        ]);
    }

    /** Two versions side by side. */
    public function diff(Request $request, Question $question, VersionDiff $diff): Response
    {
        abort_unless((int) $question->branch_id === $this->branchId($request), 404);
        abort_unless($this->editorData->allowsQuestion($request->user('web'), 'qbank.question.view', $question), 403);

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
            'versions' => $question->versions()->orderByDesc('version_no')->get(['id', 'version_no', 'status', 'decision_code'])
                ->map(fn (QuestionVersion $version): array => [
                    'id' => $version->id,
                    'versionNo' => $version->version_no,
                    'statusLabel' => $version->kmuStatus(),
                ])->values()->all(),
        ]);
    }

    private function authoriseVersion(Request $request, Question $question, QuestionVersion $version, bool $view = false): void
    {
        abort_unless((int) $version->question_id === $question->id, 404);
        abort_unless((int) $question->branch_id === $this->branchId($request), 404);

        $permission = $view ? 'qbank.question.view' : ($version->author_id === $request->user('web')->id ? 'qbank.question.edit_own' : 'qbank.question.edit_any');
        abort_unless($this->editorData->allows($request->user('web'), $permission, $version), 403);
    }

    /**
     * Whether this person has something to do on the review screen for this version: the approver's
     * decision on someone else's question, or a review they were asked for.
     */
    private function hasReviewWork(Request $request, QuestionVersion $version): bool
    {
        if (! in_array($version->status, [VersionStatus::Submitted, VersionStatus::UnderReview], true)) {
            return false;
        }
        $user = $request->user('web');

        return ($user->can('qbank.question.approve') && $version->author_id !== $user->id)
            || ReviewAssignment::query()->where('version_id', $version->id)->where('reviewer_id', $user->id)->where('status', 'open')->exists();
    }

    private function branchId(Request $request): int
    {
        return $this->activeBranch->id($request->user('web')) ?? abort(403, 'You do not work in any campus.');
    }
}
