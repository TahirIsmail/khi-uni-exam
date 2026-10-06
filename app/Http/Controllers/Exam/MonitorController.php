<?php

namespace App\Http\Controllers\Exam;

use App\Domain\Candidate\Models\Room;
use App\Domain\Delivery\Actions\EndOtherSession;
use App\Domain\Delivery\Actions\GrantCompensatingTime;
use App\Domain\Delivery\Actions\RoomPause;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Delivery\Queries\MonitorData;
use App\Domain\Exam\Models\Examination;
use App\Domain\Exam\Queries\ExaminationData;
use App\Domain\Identity\ActiveBranch;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Watching an examination while it is being sat, and the invigilator's own actions on it: ending a
 * session so a candidate may resume elsewhere, pausing a room, and adding compensating time
 * (ADR-0003). Not gated by `exam.view` or `exam.blueprint.view`, the same as the rest of Conduct Exam.
 */
class MonitorController extends Controller
{
    public function __construct(private readonly ActiveBranch $activeBranch) {}

    public function index(Request $request, Examination $exam, ExaminationData $examinations, MonitorData $monitor): Response
    {
        $this->guard($request, $exam);

        return Inertia::render('exams/conduct/Monitor', [
            'examination' => $examinations->detail($exam),
            'attempts' => $monitor->list($exam),
        ]);
    }

    public function endSession(Request $request, Examination $exam, CandidateExam $attempt, EndOtherSession $end): RedirectResponse
    {
        $this->guard($request, $exam);
        $end($request->user('web'), $attempt);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The session was ended. The candidate may sign in again.')]);

        return to_route('conduct.monitor', $exam);
    }

    public function addTime(Request $request, Examination $exam, CandidateExam $attempt, GrantCompensatingTime $grant): RedirectResponse
    {
        $this->guard($request, $exam);
        $input = $request->validate([
            'minutes' => ['required', 'integer', 'min:1', 'max:180'],
            'reason' => ['required', 'string', 'max:300'],
        ]);
        $grant($request->user('web'), $attempt, (int) $input['minutes'], $input['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Time added.')]);

        return to_route('conduct.monitor', $exam);
    }

    public function pauseRoom(Request $request, Examination $exam, Room $room, RoomPause $pause): RedirectResponse
    {
        $this->guard($request, $exam);
        $count = $pause->pause($request->user('web'), $exam, $room);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$count} attempt(s) paused."]);

        return to_route('conduct.monitor', $exam);
    }

    public function resumeRoom(Request $request, Examination $exam, Room $room, RoomPause $pause): RedirectResponse
    {
        $this->guard($request, $exam);
        $count = $pause->resume($request->user('web'), $exam, $room);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$count} attempt(s) resumed."]);

        return to_route('conduct.monitor', $exam);
    }

    protected function branchId(Request $request): int
    {
        return $this->activeBranch->id($request->user('web')) ?? abort(403, 'You do not work in any campus.');
    }

    protected function guard(Request $request, Examination $examination): void
    {
        abort_unless($examination->branch_id === $this->branchId($request), 404);
        abort_unless($request->user('web')->can('delivery.monitor'), 403, 'You cannot monitor this course\'s examinations.');
    }
}
