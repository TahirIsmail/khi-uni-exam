<?php

namespace App\Http\Controllers\Exam;

use App\Domain\Delivery\Models\CandidatePaperItem;
use App\Domain\Exam\Models\Examination;
use App\Domain\Identity\ActiveBranch;
use App\Domain\Marking\Actions\AssignExaminer;
use App\Domain\Marking\Actions\RecordAdjudication;
use App\Domain\Marking\Actions\RecordExaminerMark;
use App\Domain\Marking\Enums\ExaminerRole;
use App\Domain\Marking\Models\ExaminerAssignment;
use App\Domain\Marking\Queries\AdjudicationQueue;
use App\Domain\Marking\Queries\ExaminerCandidates;
use App\Domain\Marking\Queries\ItemMarkData;
use App\Domain\Marking\Queries\MarkingExaminationList;
use App\Domain\Marking\Queries\MarkingQueue;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Marking (exam phase, step 20): assigning examiners, marking manually-marked items against the
 * question's rubric, and adjudicating the rare disagreement. Auto-marking of objective items needs
 * nobody here — it already happened when the attempt was submitted (App\Domain\Marking\Actions\AutoMarkAttempt).
 */
class MarkingController extends Controller
{
    public function __construct(
        private readonly ActiveBranch $activeBranch,
        private readonly MarkingExaminationList $list,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user('web');
        $this->abortUnlessMarker($user);

        return Inertia::render('marking/Index', [
            'examinations' => $this->list->forBranch($this->branchId($request), $user),
            // So an empty table can say why it is empty: nobody has assigned this teacher to a
            // programme yet, rather than there being nothing to mark.
            'scopedByTeaching' => $this->list->isScopedByTeaching($user),
            'hasTeachingAssignments' => $this->list->hasTeachingAssignments($user),
        ]);
    }

    public function show(Request $request, Examination $exam, MarkingQueue $queue, AdjudicationQueue $adjudicationQueue, ExaminerCandidates $candidates): Response
    {
        $this->guard($request, $exam);
        $user = $request->user('web');

        $examiners = ExaminerAssignment::query()->where('examination_id', $exam->id)->with('user')->get()
            ->map(fn (ExaminerAssignment $a): array => ['id' => $a->id, 'role' => $a->role->value, 'roleLabel' => $a->role->label(), 'userId' => $a->user_id, 'name' => $a->user->name]);

        return Inertia::render('marking/Show', [
            'examination' => ['id' => $exam->id, 'reference' => $exam->public_ref, 'title' => $exam->title, 'requireDoubleMarking' => $exam->require_double_marking],
            'examiners' => $examiners->values()->all(),
            'examinerCandidates' => $user->can('marking.assign') ? $candidates->forExamination($exam) : [],
            'myQueue' => $queue->forExaminer($exam, $user),
            'adjudicationQueue' => $user->can('marking.adjudicate') ? $adjudicationQueue->forExamination($exam) : [],
            'can' => [
                'assign' => $user->can('marking.assign'),
                'mark' => $user->can('marking.mark'),
                'adjudicate' => $user->can('marking.adjudicate'),
            ],
        ]);
    }

    public function assignExaminer(Request $request, Examination $exam, AssignExaminer $assign): RedirectResponse
    {
        $this->guard($request, $exam);
        $input = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', Rule::enum(ExaminerRole::class)],
        ]);

        $examinerUser = User::query()->whereKey($input['user_id'])->firstOrFail();
        $assign($request->user('web'), $exam, $examinerUser, ExaminerRole::from($input['role']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Examiner assigned.')]);

        return to_route('marking.show', $exam);
    }

    public function showItem(Request $request, Examination $exam, CandidatePaperItem $item, ItemMarkData $data): Response
    {
        $this->guard($request, $exam);
        abort_unless($item->candidateExam->examination_id === $exam->id, 404);

        $result = $data->forItem($item);
        $result['marks'] = $this->hidePeerMarkIfBlind($request, $exam, $item, $result['marks']);

        return Inertia::render('marking/Item', [
            'examination' => ['id' => $exam->id, 'title' => $exam->title],
            ...$result,
        ]);
    }

    public function mark(Request $request, Examination $exam, CandidatePaperItem $item, RecordExaminerMark $record): RedirectResponse
    {
        $this->guard($request, $exam);
        abort_unless($item->candidateExam->examination_id === $exam->id, 404);

        $input = $request->validate([
            'marks_awarded' => ['required', 'numeric', 'min:0'],
            'comments' => ['nullable', 'string', 'max:500'],
            'criteria' => ['array'],
            'criteria.*.rubric_criterion_id' => ['required', 'integer'],
            'criteria.*.marks_awarded' => ['required', 'numeric', 'min:0'],
        ]);

        $record($request->user('web'), $item, (float) $input['marks_awarded'], $input['criteria'] ?? [], $input['comments'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mark recorded.')]);

        return to_route('marking.show', $exam);
    }

    public function adjudicate(Request $request, Examination $exam, CandidatePaperItem $item, RecordAdjudication $record): RedirectResponse
    {
        $this->guard($request, $exam);
        abort_unless($item->candidateExam->examination_id === $exam->id, 404);

        $input = $request->validate([
            'marks_awarded' => ['required', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $record($request->user('web'), $item, (float) $input['marks_awarded'], $input['reason'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Decision recorded.')]);

        return to_route('marking.show', $exam);
    }

    /**
     * @param  list<array<string, mixed>>  $marks
     * @return list<array<string, mixed>>
     */
    private function hidePeerMarkIfBlind(Request $request, Examination $exam, CandidatePaperItem $item, array $marks): array
    {
        $user = $request->user('web');
        $assignment = ExaminerAssignment::query()->where('examination_id', $exam->id)->where('user_id', $user->id)
            ->whereIn('role', [ExaminerRole::First, ExaminerRole::Second])->first();

        if ($assignment === null) {
            return $marks; // not an examiner (e.g. an adjudicator, or someone with marking.assign) — sees everything.
        }

        $mySource = $assignment->role === ExaminerRole::First ? 'examiner_1' : 'examiner_2';
        $haveIMarked = collect($marks)->contains('source', $mySource);
        if ($haveIMarked) {
            return $marks; // both marks are already comparable once mine is in.
        }

        $peerSource = $mySource === 'examiner_1' ? 'examiner_2' : 'examiner_1';

        return array_values(array_filter($marks, fn (array $m): bool => $m['source'] !== $peerSource));
    }

    private function branchId(Request $request): int
    {
        return $this->activeBranch->id($request->user('web')) ?? abort(403, 'You do not work in any campus.');
    }

    private function guard(Request $request, Examination $examination): void
    {
        abort_unless($examination->branch_id === $this->branchId($request), 404);
        $user = $request->user('web');
        $this->abortUnlessMarker($user);

        // The same rule the list uses, so an examination that is not on somebody's Marking screen
        // cannot be reached by typing its address either.
        abort_unless($this->list->maySee($user, $examination), 404);
    }

    /** Marking is opened by anybody holding any one of its three permissions. */
    private function abortUnlessMarker(User $user): void
    {
        abort_unless(
            $user->can('marking.assign') || $user->can('marking.mark') || $user->can('marking.adjudicate'),
            403,
            'You cannot open marking for this course.'
        );
    }
}
