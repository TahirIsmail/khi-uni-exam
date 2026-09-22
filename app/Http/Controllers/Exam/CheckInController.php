<?php

namespace App\Http\Controllers\Exam;

use App\Domain\Candidate\Actions\CheckInCandidate;
use App\Domain\Candidate\Actions\ReissuePin;
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
        abort_unless($request->user()->can('candidate.checkin'), 403, 'You cannot check candidates in for this course.');

        $search = trim((string) $request->query('search', ''));
        $found = $search === '' ? [] : Candidate::query()->where('examination_id', $exam->id)
            ->where(fn ($q) => $q->where('candidate_no', $search)->orWhere('roll_no', $search)->orWhere('name', 'like', "%{$search}%"))
            ->with(['centre', 'room'])->orderBy('candidate_no')->limit(20)->get()
            ->map(fn (Candidate $candidate): array => [
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
            ])->all();

        return Inertia::render('exams/conduct/CheckIn', [
            'examination' => $examinations->detail($exam),
            'search' => $search,
            'results' => array_values($found),
        ]);
    }

    public function checkIn(Request $request, Examination $exam, Candidate $candidate, CheckInCandidate $checkIn): RedirectResponse
    {
        $this->guard($request, $exam);
        $result = $checkIn($request->user(), $exam, $candidate);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Checked in.')]);
        Inertia::flash('pin', ['candidateNo' => $result['candidate']->candidate_no, 'pin' => $result['pin']]);

        return to_route('conduct.checkin', [$exam, 'search' => $result['candidate']->candidate_no]);
    }

    public function reissuePin(Request $request, Examination $exam, Candidate $candidate, ReissuePin $reissue): RedirectResponse
    {
        $this->guard($request, $exam);
        $result = $reissue($request->user(), $exam, $candidate);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('A new PIN was issued.')]);
        Inertia::flash('pin', ['candidateNo' => $result['candidate']->candidate_no, 'pin' => $result['pin']]);

        return to_route('conduct.checkin', [$exam, 'search' => $result['candidate']->candidate_no]);
    }

    protected function branchId(Request $request): int
    {
        return $this->activeBranch->id($request->user()) ?? abort(403, 'You do not work in any campus.');
    }

    protected function guard(Request $request, Examination $examination): void
    {
        abort_unless($examination->branch_id === $this->branchId($request), 404);
    }
}
