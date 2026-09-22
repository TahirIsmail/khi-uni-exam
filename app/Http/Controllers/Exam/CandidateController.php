<?php

namespace App\Http\Controllers\Exam;

use App\Domain\Candidate\Actions\AllocateCandidates;
use App\Domain\Candidate\Actions\GrantExtraTime;
use App\Domain\Candidate\Actions\ImportCandidates;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Candidate\Models\Centre;
use App\Domain\Candidate\Models\Room;
use App\Domain\Candidate\Queries\CandidateData;
use App\Domain\Candidate\Queries\CentreData;
use App\Domain\Exam\Models\Examination;
use App\Domain\Exam\Queries\ExaminationData;
use App\Domain\Identity\ActiveBranch;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An examination's candidate roster: imported, allocated to a seat, and where extra time is granted.
 * Checking them in is CheckInController, next door.
 *
 * Unlike ExamAreaController's screens, this is not gated by `exam.view` or `exam.blueprint.view` —
 * a person who only conducts exams, and never builds one, still needs to reach it — so each action
 * checks its own candidate.* or centre.* right instead (App\Domain\Candidate\Actions\CandidateGuard).
 */
class CandidateController extends Controller
{
    public function __construct(private readonly ActiveBranch $activeBranch) {}

    public function index(Request $request, Examination $exam, ExaminationData $examinations, CandidateData $candidates, CentreData $centres): Response
    {
        $this->guard($request, $exam);
        abort_unless($request->user()->can('candidate.view'), 403, 'You cannot see this course\'s candidates.');

        return Inertia::render('exams/conduct/Candidates', [
            'examination' => $examinations->detail($exam),
            'candidates' => $candidates->list($exam),
            'summary' => $candidates->summary($exam),
            'can' => $candidates->abilities($request->user(), $exam),
            'centres' => $centres->choices($exam->branch_id),
        ]);
    }

    public function import(Request $request, Examination $exam, ImportCandidates $import): RedirectResponse
    {
        $this->guard($request, $exam);
        $input = $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:csv,txt']]);

        $result = $import($request->user(), $exam, $input['file']);

        $message = $result['imported'].' candidate(s) added.'.($result['skipped'] > 0 ? " {$result['skipped']} row(s) were skipped — see below." : '');
        Inertia::flash('toast', ['type' => $result['skipped'] > 0 ? 'info' : 'success', 'message' => $message]);
        Inertia::flash('importResult', $result);

        return to_route('conduct.candidates', $exam);
    }

    public function allocateAuto(Request $request, Examination $exam, AllocateCandidates $allocate): RedirectResponse
    {
        $this->guard($request, $exam);
        $input = $request->validate(['centre_id' => ['required', 'integer', 'min:1']]);
        $centre = Centre::query()->findOrFail((int) $input['centre_id']);

        $result = $allocate->auto($request->user(), $exam, $centre);

        Inertia::flash('toast', ['type' => 'success', 'message' => $result['allocated'].' candidate(s) allocated.'.($result['unseated'] > 0 ? ' '.$result['unseated'].' could not be seated — add more room capacity.' : '')]);

        return to_route('conduct.candidates', $exam);
    }

    public function allocateOne(Request $request, Examination $exam, Candidate $candidate, AllocateCandidates $allocate): RedirectResponse
    {
        $this->guard($request, $exam);
        $input = $request->validate([
            'room_id' => ['required', 'integer', 'min:1'],
            'seat_no' => ['nullable', 'string', 'max:20'],
        ]);
        $room = Room::query()->findOrFail((int) $input['room_id']);

        $allocate->one($request->user(), $exam, $candidate, $room, $input['seat_no'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Allocated.')]);

        return to_route('conduct.candidates', $exam);
    }

    public function extraTime(Request $request, Examination $exam, Candidate $candidate, GrantExtraTime $grant): RedirectResponse
    {
        $this->guard($request, $exam);
        $input = $request->validate([
            'minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $grant($request->user(), $exam, $candidate, $input['minutes'] ?? null, $input['reason'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return to_route('conduct.candidates', $exam);
    }

    protected function branchId(Request $request): int
    {
        return $this->activeBranch->id($request->user()) ?? abort(403, 'You do not work in any campus.');
    }

    /** An examination of another campus does not exist for this user. */
    protected function guard(Request $request, Examination $examination): void
    {
        abort_unless($examination->branch_id === $this->branchId($request), 404);
    }
}
