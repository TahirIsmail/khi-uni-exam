<?php

namespace App\Http\Controllers\Exam;

use App\Domain\Candidate\Actions\CheckInAll;
use App\Domain\Candidate\Actions\CheckInCandidate;
use App\Domain\Candidate\Actions\ReissuePin;
use App\Domain\Candidate\Enums\CandidateStatus;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Exam\Models\Examination;
use App\Domain\Exam\Queries\ExaminationData;
use App\Domain\Identity\ActiveBranch;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Checking a candidate in on the exam day and handing them their one-time PIN (ADR-0003). Finding a
 * candidate is by their candidate number or roll number, in this examination only.
 */
class CheckInController extends Controller
{
    public function __construct(private readonly ActiveBranch $activeBranch) {}

    public function index(Request $request, Examination $exam, ExaminationData $examinations): Response
    {
        $this->guard($request, $exam);
        abort_unless($request->user('web')->can('candidate.checkin'), 403, 'You cannot check candidates in for this course.');

        // Everybody on the list, a page at a time; a search narrows it.
        $search = trim((string) $request->query('search', ''));
        $page = Candidate::query()->where('examination_id', $exam->id)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('candidate_no', 'like', "%{$search}%")->orWhere('roll_no', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->with(['centre', 'room'])->orderBy('candidate_no')->paginate(100)->withQueryString();

        $counts = Candidate::query()->where('examination_id', $exam->id)->toBase()
            ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status'); // raw-sql-reviewed: constant aggregate, no input

        return Inertia::render('exams/conduct/CheckIn', [
            'examination' => $examinations->detail($exam),
            'search' => $search,
            'results' => array_values(array_map(fn (Candidate $candidate): array => [
                'id' => $candidate->id,
                'candidateNo' => $candidate->candidate_no,
                'name' => $candidate->name,
                'rollNo' => $candidate->roll_no,
                'status' => $candidate->status->value,
                'statusLabel' => $candidate->status->label(),
                'centre' => $candidate->centre?->name,
                'room' => $candidate->room?->name,
                'seatNo' => $candidate->seat_no,
                'checkedInAt' => $candidate->checked_in_at?->toIso8601String(),
            ], $page->items())),
            'pages' => ['current' => $page->currentPage(), 'last' => $page->lastPage(), 'total' => $page->total()],
            'counts' => [
                'enrolled' => (int) ($counts[CandidateStatus::Enrolled->value] ?? 0),
                'allocated' => (int) ($counts[CandidateStatus::Allocated->value] ?? 0),
                'checkedIn' => (int) ($counts[CandidateStatus::CheckedIn->value] ?? 0),
            ],
        ]);
    }

    public function checkIn(Request $request, Examination $exam, Candidate $candidate, CheckInCandidate $checkIn): RedirectResponse
    {
        $this->guard($request, $exam);
        $result = $checkIn($request->user('web'), $exam, $candidate);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':no is checked in.', ['no' => $result['candidate']->candidate_no])]);
        if ($result['pin'] !== null) {
            Inertia::flash('pin', ['candidateNo' => $result['candidate']->candidate_no, 'pin' => $result['pin']]);
        }

        return back();
    }

    /** Everybody not checked in yet (or the ticked ones), in one go; their own PINs, if any, are shown once to print. */
    public function checkInAll(Request $request, Examination $exam, CheckInAll $checkInAll): RedirectResponse
    {
        $this->guard($request, $exam);
        $only = $request->validate(['candidate_ids' => ['sometimes', 'array', 'max:5000'], 'candidate_ids.*' => ['integer', 'min:1']])['candidate_ids'] ?? null;
        $result = $checkInAll($request->user('web'), $exam, $only === null ? null : array_values(array_map('intval', $only)));
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':n candidates checked in.', ['n' => $result['checkedIn']])
            .($result['notSeated'] > 0 ? ' '.__(':n have no seat yet and were left out.', ['n' => $result['notSeated']]) : '')]);
        if ($result['pins'] !== []) {
            Inertia::flash('pins', $result['pins']);
        }

        return to_route('conduct.checkin', $exam);
    }

    public function reissuePin(Request $request, Examination $exam, Candidate $candidate, ReissuePin $reissue): RedirectResponse
    {
        $this->guard($request, $exam);
        $result = $reissue($request->user('web'), $exam, $candidate);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('A new PIN was issued.')]);
        Inertia::flash('pin', ['candidateNo' => $result['candidate']->candidate_no, 'pin' => $result['pin']]);

        return to_route('conduct.checkin', [$exam, 'search' => $result['candidate']->candidate_no]);
    }

    protected function branchId(Request $request): int
    {
        return $this->activeBranch->id($request->user('web')) ?? abort(403, 'You do not work in any campus.');
    }

    protected function guard(Request $request, Examination $examination): void
    {
        abort_unless($examination->branch_id === $this->branchId($request), 404);
    }
}
