<?php

namespace App\Http\Controllers\Exam;

use App\Domain\Delivery\Actions\DecideProctorCase;
use App\Domain\Delivery\Enums\ProctorDecisionType;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Delivery\Queries\MonitorData;
use App\Domain\Delivery\Queries\ProctorCaseData;
use App\Domain\Exam\Models\Examination;
use App\Domain\Exam\Queries\ExaminationData;
use App\Domain\Identity\ActiveBranch;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reviewing proctoring cases and recording the committee's decision against one (exam phase,
 * step 19). Separate from Monitor, which watches an exam live — this is read after the fact, by
 * whoever holds the review right rather than the invigilator's own.
 */
class ProctorController extends Controller
{
    public function __construct(private readonly ActiveBranch $activeBranch) {}

    public function index(Request $request, Examination $exam, ExaminationData $examinations, MonitorData $monitor): Response
    {
        $this->guard($request, $exam, 'proctor.events.view');

        return Inertia::render('exams/conduct/Proctoring', [
            'examination' => $examinations->detail($exam),
            'attempts' => $monitor->list($exam),
        ]);
    }

    public function show(Request $request, Examination $exam, CandidateExam $attempt, ProctorCaseData $data): Response
    {
        $this->guard($request, $exam, 'proctor.events.view');
        abort_unless($attempt->examination_id === $exam->id, 404);

        return Inertia::render('exams/conduct/ProctorCase', [
            'examination' => ['id' => $exam->id, 'title' => $exam->title],
            'proctorCase' => $data->forAttempt($attempt),
            'can' => ['decide' => $request->user('web')->can('proctor.review.decide')],
        ]);
    }

    public function decide(Request $request, Examination $exam, CandidateExam $attempt, DecideProctorCase $decide): RedirectResponse
    {
        $this->guard($request, $exam, 'proctor.review.decide');
        abort_unless($attempt->examination_id === $exam->id, 404);

        $input = $request->validate([
            'decision' => ['required', 'string', 'in:no_action,warning,flagged_for_review,void_attempt'],
            'reason' => ['required', 'string', 'max:500'],
            'covers_from' => ['nullable', 'date'],
            'covers_to' => ['nullable', 'date'],
        ]);

        $decide(
            $request->user('web'),
            $attempt,
            ProctorDecisionType::from($input['decision']),
            $input['reason'],
            isset($input['covers_from']) ? Carbon::parse($input['covers_from']) : null,
            isset($input['covers_to']) ? Carbon::parse($input['covers_to']) : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Decision recorded.')]);

        return to_route('conduct.proctoring.case', [$exam, $attempt]);
    }

    private function branchId(Request $request): int
    {
        return $this->activeBranch->id($request->user('web')) ?? abort(403, 'You do not work in any campus.');
    }

    private function guard(Request $request, Examination $examination, string $permission): void
    {
        abort_unless($examination->branch_id === $this->branchId($request), 404);
        abort_unless($request->user('web')->can($permission), 403, 'You cannot review proctoring cases for this course.');
    }
}
