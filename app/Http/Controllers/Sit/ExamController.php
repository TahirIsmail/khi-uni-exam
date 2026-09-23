<?php

namespace App\Http\Controllers\Sit;

use App\Domain\Candidate\Actions\RegisterOrCheckDevice;
use App\Domain\Delivery\Actions\EnforceDeadline;
use App\Domain\Delivery\Actions\Heartbeat;
use App\Domain\Delivery\Actions\RecordAnswer;
use App\Domain\Delivery\Actions\RecordProctorEvent;
use App\Domain\Delivery\Actions\SubmitAttempt;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Enums\ProctorEventType;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Delivery\Models\CandidatePaperItem;
use App\Domain\Delivery\Models\DeliverySession;
use App\Domain\Delivery\Queries\AttemptData;
use App\Domain\Exam\Models\Examination;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The exam itself: one page, loaded once, that then autosaves every answer and sends a heartbeat by
 * plain requests — not by navigating away, which would cost time and risk losing what is on screen.
 */
class ExamController extends Controller
{
    public function show(Request $request, Examination $exam, AttemptData $data, EnforceDeadline $enforceDeadline): Response|RedirectResponse
    {
        $attempt = $this->attemptFor($request, $exam);
        $attempt = $enforceDeadline($attempt);

        if ($attempt->status === AttemptStatus::Submitted) {
            return to_route('sit.submitted', $exam);
        }

        return Inertia::render('sit/Exam', [
            'examination' => [
                'id' => $exam->id,
                'title' => $exam->title,
                'instructions' => $exam->instructions,
            ],
            ...$data->screen($attempt),
        ]);
    }

    public function heartbeat(Request $request, Examination $exam, Heartbeat $heartbeat, AttemptData $data): JsonResponse
    {
        $attempt = $this->attemptFor($request, $exam);
        $session = $this->sessionFor($request, $attempt);
        $attempt = $heartbeat($attempt, $session);

        return response()->json([
            'status' => $attempt->status->value,
            'remainingSeconds' => $data->remainingSeconds($attempt),
        ]);
    }

    public function answer(Request $request, Examination $exam, RecordAnswer $record): JsonResponse
    {
        $input = $request->validate([
            'item_id' => ['required', 'integer', 'min:1'],
            'sequence' => ['required', 'integer', 'min:1'],
            'payload' => ['present', 'array'],
            'flagged' => ['boolean'],
            'client_time' => ['nullable', 'date'],
        ]);

        $attempt = $this->attemptFor($request, $exam);
        $session = $this->sessionFor($request, $attempt);
        $item = CandidatePaperItem::query()->findOrFail((int) $input['item_id']);

        $record(
            $attempt,
            $session,
            $item,
            (int) $input['sequence'],
            $input['payload'],
            (bool) ($input['flagged'] ?? false),
            isset($input['client_time']) ? Carbon::parse($input['client_time']) : null,
        );

        return response()->json(['ok' => true, 'sequence' => $input['sequence']]);
    }

    public function device(Request $request, Examination $exam, RegisterOrCheckDevice $check): JsonResponse
    {
        $input = $request->validate(['fingerprint' => ['required', 'string', 'max:255']]);

        $attempt = $this->attemptFor($request, $exam);
        $result = $check($attempt, $input['fingerprint']);

        return response()->json(['status' => $result['status']]);
    }

    public function proctorEvent(Request $request, Examination $exam, RecordProctorEvent $record): JsonResponse
    {
        $input = $request->validate([
            'type' => ['required', Rule::enum(ProctorEventType::class)],
            'detail' => ['nullable', 'array'],
        ]);

        $attempt = $this->attemptFor($request, $exam);
        $session = $this->sessionFor($request, $attempt);

        $record($attempt, $session, ProctorEventType::from($input['type']), $input['detail'] ?? []);

        return response()->json(['ok' => true]);
    }

    public function submit(Request $request, Examination $exam, SubmitAttempt $submit): RedirectResponse
    {
        $attempt = $this->attemptFor($request, $exam);
        $submit($attempt, 'candidate');

        $request->session()->forget('dlv_session_id');

        return to_route('sit.submitted', $exam);
    }

    public function logout(Request $request, Examination $exam): RedirectResponse
    {
        $sessionId = $request->session()->get('dlv_session_id');
        if (is_int($sessionId)) {
            DeliverySession::query()->whereKey($sessionId)->whereNull('ended_at')
                ->update(['ended_at' => now(), 'end_reason' => 'logged_out']);
        }

        Auth::guard('candidate')->logout();
        $request->session()->forget('dlv_session_id');

        return to_route('sit.login', $exam);
    }

    private function attemptFor(Request $request, Examination $exam): CandidateExam
    {
        $candidate = $request->user('candidate');

        return CandidateExam::query()
            ->where('candidate_id', $candidate->id)
            ->where('examination_id', $exam->id)
            ->firstOrFail();
    }

    private function sessionFor(Request $request, CandidateExam $attempt): DeliverySession
    {
        $sessionId = $request->session()->get('dlv_session_id');

        return DeliverySession::query()
            ->where('candidate_exam_id', $attempt->id)
            ->whereKey($sessionId)
            ->firstOrFail();
    }
}
