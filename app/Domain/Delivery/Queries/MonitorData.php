<?php

namespace App\Domain\Delivery\Queries;

use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Exam\Models\Examination;
use Illuminate\Support\Facades\DB;

/**
 * Who is sitting an examination right now, for the invigilator: where they stand, how much time is
 * left, and whether their computer is still there.
 */
final class MonitorData
{
    /**
     * @return list<array<string, mixed>>
     */
    public function list(Examination $examination): array
    {
        $staleAfter = (int) config('exam.delivery.session_stale_after_seconds');

        $attempts = CandidateExam::query()->where('examination_id', $examination->id)
            ->with(['candidate.room', 'candidate.centre'])
            ->orderBy('id')->get();

        $sessions = DB::table('dlv_sessions')->whereIn('candidate_exam_id', $attempts->pluck('id'))
            ->whereNull('ended_at')->get()->keyBy('candidate_exam_id');

        return array_values($attempts->map(function (CandidateExam $attempt) use ($sessions, $staleAfter): array {
            $session = $sessions->get($attempt->id);
            $heartbeatAgeSeconds = $session === null ? null : now()->diffInSeconds($session->last_heartbeat_at);

            return [
                'id' => $attempt->id,
                'candidateNo' => $attempt->candidate->candidate_no,
                'name' => $attempt->candidate->name,
                'roomId' => $attempt->candidate->room_id,
                'room' => $attempt->candidate->room?->name,
                'status' => $attempt->status->value,
                'statusLabel' => $attempt->status->label(),
                'startedAt' => $attempt->started_at?->toIso8601String(),
                'remainingSeconds' => $attempt->deadline_at === null ? null : max(0, (int) now()->diffInSeconds($attempt->deadline_at, false)),
                'hasOpenSession' => $session !== null,
                'heartbeatAgeSeconds' => $heartbeatAgeSeconds,
                'sessionAlive' => $heartbeatAgeSeconds !== null && $heartbeatAgeSeconds < $staleAfter,
            ];
        })->values()->all());
    }
}
