<?php

namespace App\Http\Controllers\Exam;

use App\Domain\Exam\Models\Examination;
use App\Domain\Exam\Queries\ExaminationData;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\Paper\Actions\ChangePaperItems;
use App\Domain\Paper\Actions\CreatePaper;
use App\Domain\Paper\Actions\FillPaper;
use App\Domain\Paper\Actions\PaperComments;
use App\Domain\Paper\Actions\PaperGuard;
use App\Domain\Paper\Actions\PaperWorkflow;
use App\Domain\Paper\Actions\StartNewPaperVersion;
use App\Domain\Paper\Actions\UpdatePaperSettings;
use App\Domain\Paper\Models\Paper;
use App\Domain\Paper\Models\PaperComment;
use App\Domain\Paper\Models\PaperItem;
use App\Domain\Paper\PaperItems;
use App\Domain\Paper\Queries\PaperData;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Queries\QuestionEditorData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The paper of an examination: choosing its questions from the question bank to match the approved
 * blueprint, moderating it, and locking it. Reading it needs the right to see papers; every change
 * needs its own right, and each action checks that, the campus and the course again.
 */
class PaperController extends ExamAreaController
{
    public function show(Request $request, Examination $exam, ExaminationData $examinations, PaperData $data): Response
    {
        $this->guard($request, $exam);
        $this->mustSeePapers($request, $exam);

        $input = $request->validate(['version' => ['nullable', 'integer', 'min:1']]);
        $paper = isset($input['version'])
            ? Paper::query()->where('examination_id', $exam->id)->where('version_no', (int) $input['version'])->firstOrFail()
            : $this->paperOf($exam);

        return Inertia::render('exams/Paper', [
            'examination' => $examinations->detail($exam),
            ...$data->screen($request->user('web'), $exam, $paper),
        ]);
    }

    /**
     * The whole paper to read through: the examination's details, then every question in order with
     * its marks — and the answer key, for whoever turns it on. Only for those who may read a paper's
     * questions (PaperData::mayRead), not merely count them.
     */
    public function preview(Request $request, Examination $exam, ExaminationData $examinations, PaperData $data, QuestionEditorData $editorData): Response
    {
        $this->guard($request, $exam);
        $this->mustSeePapers($request, $exam);
        abort_unless($data->mayRead($request->user('web'), $exam), 403, 'You cannot read the questions of this paper.');

        $input = $request->validate(['version' => ['nullable', 'integer', 'min:1']]);
        $paper = isset($input['version'])
            ? Paper::query()->where('examination_id', $exam->id)->where('version_no', (int) $input['version'])->firstOrFail()
            : $this->paperOrFail($exam);

        $items = PaperItem::query()->where('paper_id', $paper->id)->orderBy('position')->get();
        $versions = QuestionVersion::query()->whereIn('id', $items->pluck('version_id'))->with('question:id,public_ref')->get()->keyBy('id');

        return Inertia::render('exams/PaperPreview', [
            'examination' => $examinations->detail($exam),
            'paper' => [
                'versionNo' => $paper->version_no,
                'statusLabel' => $paper->status->label(),
                'shuffleQuestions' => (bool) $paper->shuffle_questions,
                'shuffleOptions' => (bool) $paper->shuffle_options,
            ],
            'questions' => array_values($items->map(function (PaperItem $item) use ($versions, $editorData): ?array {
                $version = $versions->get($item->version_id);

                return $version === null ? null : [
                    'number' => $item->position,
                    'reference' => $version->question->public_ref.' v'.$version->version_no,
                    // The marks this paper gives it, which may differ from the question bank's.
                    'version' => ['marks' => (float) $item->marks] + $editorData->version($version),
                ];
            })->filter()->all()),
            'types' => $editorData->lookups()['types'],
        ]);
    }

    public function store(Request $request, Examination $exam, CreatePaper $create): RedirectResponse
    {
        $this->guard($request, $exam);
        $create($request->user('web'), $exam);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The paper is started. Fill it from the question bank, or choose the questions yourself.')]);

        return to_route('papers.show', $exam);
    }

    public function update(Request $request, Examination $exam, UpdatePaperSettings $update): RedirectResponse
    {
        $this->guard($request, $exam);
        $input = $request->validate([
            'shuffle_questions' => ['required', 'boolean'],
            'shuffle_options' => ['required', 'boolean'],
        ]);

        $update($request->user('web'), $exam, $this->paperOrFail($exam), (bool) $input['shuffle_questions'], (bool) $input['shuffle_options']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return back();
    }

    /** Draws the questions the blueprint still asks for: the gaps only, or everything not locked. */
    public function fill(Request $request, Examination $exam, FillPaper $fill): RedirectResponse
    {
        $this->guard($request, $exam);
        $input = $request->validate(['mode' => ['required', Rule::in(['gaps', 'redraw'])]]);

        $result = $fill($request->user('web'), $exam, $this->paperOrFail($exam), (string) $input['mode']);

        Inertia::flash('toast', [
            'type' => $result['missing'] > 0 ? 'warning' : 'success',
            'message' => $result['missing'] > 0
                ? __(':added questions chosen; :missing more are needed than the question bank can give.', ['added' => $result['added'], 'missing' => $result['missing']])
                : __(':added questions chosen.', ['added' => $result['added']]),
        ]);

        return back();
    }

    /** The picker: the questions a row could take, for the words typed. */
    public function candidates(Request $request, Examination $exam, PaperItems $items, PaperGuard $guard, PaperData $data): JsonResponse
    {
        $this->guard($request, $exam);
        $guard->authorise($request->user('web'), $exam);
        $input = $request->validate($this->slotRules() + ['search' => ['nullable', 'string', 'max:100']]);

        $slot = $items->slot($exam, (int) $input['node_id'], (int) $input['question_type_id'], (float) $input['marks_each'], $this->section($input));

        return response()->json(['candidates' => $data->candidates($request->user('web'), $exam, $this->paperOrFail($exam), $slot, (string) ($input['search'] ?? ''))]);
    }

    public function addItem(Request $request, Examination $exam, ChangePaperItems $change): RedirectResponse
    {
        $this->guard($request, $exam);
        $input = $request->validate($this->slotRules() + ['question_id' => ['required', 'integer', 'min:1']]);

        $change->add($request->user('web'), $exam, $this->paperOrFail($exam), (int) $input['node_id'], (int) $input['question_type_id'], (float) $input['marks_each'], $this->section($input), (int) $input['question_id']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Question added.')]);

        return back();
    }

    public function swapItem(Request $request, Examination $exam, PaperItem $item, ChangePaperItems $change): RedirectResponse
    {
        $this->guard($request, $exam);
        $input = $request->validate(['question_id' => ['required', 'integer', 'min:1']]);

        $change->swap($request->user('web'), $exam, $this->paperOrFail($exam), $item, (int) $input['question_id']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Question swapped.')]);

        return back();
    }

    public function removeItem(Request $request, Examination $exam, PaperItem $item, ChangePaperItems $change): RedirectResponse
    {
        $this->guard($request, $exam);
        $change->remove($request->user('web'), $exam, $this->paperOrFail($exam), $item);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Question taken out.')]);

        return back();
    }

    public function lockItem(Request $request, Examination $exam, PaperItem $item, ChangePaperItems $change): RedirectResponse
    {
        $this->guard($request, $exam);
        $input = $request->validate(['locked' => ['required', 'boolean']]);

        $change->lock($request->user('web'), $exam, $this->paperOrFail($exam), $item, (bool) $input['locked']);

        return back();
    }

    public function submit(Request $request, Examination $exam, PaperWorkflow $workflow): RedirectResponse
    {
        $this->guard($request, $exam);
        $workflow->submit($request->user('web'), $exam, $this->paperOrFail($exam));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Submitted for moderation.')]);

        return to_route('papers.show', $exam);
    }

    public function approve(Request $request, Examination $exam, PaperWorkflow $workflow): RedirectResponse
    {
        $this->guard($request, $exam);
        $workflow->approve($request->user('web'), $exam, $this->paperOrFail($exam));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Approved. It can now be finalised.')]);

        return to_route('papers.show', $exam);
    }

    public function sendBack(Request $request, Examination $exam, PaperWorkflow $workflow): RedirectResponse
    {
        $this->guard($request, $exam);
        $input = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $workflow->returnToDraft($request->user('web'), $exam, $this->paperOrFail($exam), (string) $input['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sent back with your reason.')]);

        return to_route('papers.show', $exam);
    }

    public function finalise(Request $request, Examination $exam, PaperWorkflow $workflow): RedirectResponse
    {
        $this->guard($request, $exam);
        $workflow->finalise($request->user('web'), $exam, $this->paperOrFail($exam));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Finalised and locked.')]);

        return to_route('papers.show', $exam);
    }

    public function publish(Request $request, Examination $exam, PaperWorkflow $workflow): RedirectResponse
    {
        $this->guard($request, $exam);
        $workflow->publish($request->user('web'), $exam, $this->paperOrFail($exam));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Published, ready for delivery.')]);

        return to_route('papers.show', $exam);
    }

    /** A new draft version of a finalised or published paper, to correct it. */
    public function newVersion(Request $request, Examination $exam, StartNewPaperVersion $start): RedirectResponse
    {
        $this->guard($request, $exam);
        $start($request->user('web'), $exam, $this->paperOrFail($exam));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('A new version is ready to change.')]);

        return to_route('papers.show', $exam);
    }

    public function addComment(Request $request, Examination $exam, PaperComments $comments): RedirectResponse
    {
        $this->guard($request, $exam);
        $input = $request->validate([
            'item_id' => ['nullable', 'integer', 'min:1'],
            'body' => ['required', 'string', 'max:2000'],
        ]);
        $item = isset($input['item_id']) ? PaperItem::query()->whereKey($input['item_id'])->firstOrFail() : null;

        $comments->add($request->user('web'), $exam, $this->paperOrFail($exam), $item, (string) $input['body']);

        return back();
    }

    public function resolveComment(Request $request, Examination $exam, PaperComment $comment, PaperComments $comments): RedirectResponse
    {
        $this->guard($request, $exam);
        $input = $request->validate(['resolved' => ['required', 'boolean']]);

        $comments->resolve($request->user('web'), $exam, $this->paperOrFail($exam), $comment, (bool) $input['resolved']);

        return back();
    }

    /** Reading a paper is a right of its own, on top of seeing the examination. */
    private function mustSeePapers(Request $request, Examination $exam): void
    {
        abort_unless(
            $this->access->allows($request->user('web'), 'exam.view', new ScopeTarget($exam->branch_id, $exam->programme_id, $exam->professional_id, $exam->course_id)),
            403,
            'You cannot open the papers of this course.',
        );
    }

    private function paperOf(Examination $exam): ?Paper
    {
        return Paper::query()->where('examination_id', $exam->id)->orderByDesc('version_no')->first();
    }

    private function paperOrFail(Examination $exam): Paper
    {
        return $this->paperOf($exam) ?? abort(404, 'This examination has no paper yet.');
    }

    /**
     * @return array<string, mixed>
     */
    private function slotRules(): array
    {
        return [
            'node_id' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'question_type_id' => ['required', 'integer', 'min:1', 'max:255'],
            'marks_each' => ['required', 'numeric', 'gt:0', 'max:9999'],
            'section' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function section(array $input): ?string
    {
        $section = isset($input['section']) ? trim((string) $input['section']) : '';

        return $section === '' ? null : $section;
    }
}
