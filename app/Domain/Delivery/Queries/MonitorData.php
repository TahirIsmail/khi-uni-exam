<?php

namespace App\Domain\Delivery\Queries;

use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Exam\Models\Examination;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who is sitting an examination right now, for the invigilator: where they stand, how much time is
 * left, and whether their computer is still there.
 */
final class MonitorData
{
    /**
     * One page of the examination's attempts, and the count of the whole examination by where they
     * stand — built for a hall of thousands: one query for the page (no model per row), one for the
     * counts, and the heartbeats and proctoring events of that page only.
     *
     * @return array{rows: list<array<string, mixed>>, pages: array{current: int, last: int, total: int}, summary: array<string, int>}
     */
    public function page(Examination $examination, int $page = 1, string $search = '', int $perPage = 200): array
    {
        $staleAfter = (int) config('exam.delivery.session_stale_after_seconds');
        $base = DB::table('cand_candidate_exams as ce')
            ->join('cand_candidates as c', 'c.id', '=', 'ce.candidate_id')
            ->where('ce.examination_id', $examination->id)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('c.candidate_no', 'like', '%'.addcslashes($search, '\\%_').'%')
                ->orWhere('c.name', 'like', '%'.addcslashes($search, '\\%_').'%')));

        $total = (clone $base)->count();
        $last = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $last);

        $rows = (clone $base)->leftJoin('cand_rooms as r', 'r.id', '=', 'c.room_id')
            ->orderBy('c.candidate_no')->forPage($page, $perPage)
            ->get(['ce.id', 'ce.status', 'ce.started_at', 'ce.deadline_at', 'c.candidate_no', 'c.name', 'c.room_id', 'r.name as room']);
        $ids = $rows->pluck('id')->all();

        $sessions = DB::table('dlv_sessions')->whereIn('candidate_exam_id', $ids)->whereNull('ended_at')
            ->get(['candidate_exam_id', 'last_heartbeat_at'])->keyBy('candidate_exam_id');
        $eventCounts = DB::table('dlv_proctor_events')->whereIn('candidate_exam_id', $ids)
            ->selectRaw('candidate_exam_id, count(*) as total, max(case severity when "high" then 3 when "medium" then 2 else 1 end) as max_rank') // raw-sql-reviewed: no user input, fixed severity literals only
            ->groupBy('candidate_exam_id')->get()->keyBy('candidate_exam_id');
        $severityByRank = [3 => 'high', 2 => 'medium', 1 => 'low'];

        $summary = DB::table('cand_candidate_exams')->where('examination_id', $examination->id)
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status') // raw-sql-reviewed: constant aggregate, no input
            ->map(fn (mixed $n): int => (int) $n)->all();
        $offline = DB::table('cand_candidate_exams as ce')->where('ce.examination_id', $examination->id)->where('ce.status', AttemptStatus::InProgress->value)
            ->whereNotExists(fn ($q) => $q->from('dlv_sessions as s')->whereColumn('s.candidate_exam_id', 'ce.id')->whereNull('s.ended_at')
                ->where('s.last_heartbeat_at', '>=', now()->subSeconds($staleAfter)))
            ->count();

        return [
            'rows' => array_values($rows->map(function (object $row) use ($sessions, $staleAfter, $eventCounts, $severityByRank): array {
                $session = $sessions->get($row->id);
                $heartbeatAgeSeconds = $session === null ? null : (int) now()->diffInSeconds(Carbon::parse($session->last_heartbeat_at));
                $events = $eventCounts->get($row->id);
                $status = AttemptStatus::from((string) $row->status);

                return [
                    'id' => (int) $row->id,
                    'candidateNo' => (string) $row->candidate_no,
                    'name' => (string) $row->name,
                    'roomId' => $row->room_id === null ? null : (int) $row->room_id,
                    'room' => $row->room,
                    'status' => $status->value,
                    'statusLabel' => $status->label(),
                    'startedAt' => $row->started_at === null ? null : Carbon::parse($row->started_at)->toIso8601String(),
                    'remainingSeconds' => $row->deadline_at === null ? null : max(0, (int) now()->diffInSeconds(Carbon::parse($row->deadline_at), false)),
                    'hasOpenSession' => $session !== null,
                    'heartbeatAgeSeconds' => $heartbeatAgeSeconds,
                    'sessionAlive' => $heartbeatAgeSeconds !== null && $heartbeatAgeSeconds < $staleAfter,
                    'proctorEventCount' => $events === null ? 0 : (int) $events->total,
                    'proctorHighestSeverity' => $events === null ? null : $severityByRank[(int) $events->max_rank],
                ];
            })->all()),
            'pages' => ['current' => $page, 'last' => $last, 'total' => $total],
            'summary' => [
                'notStarted' => $summary[AttemptStatus::NotStarted->value] ?? 0,
                'inProgress' => $summary[AttemptStatus::InProgress->value] ?? 0,
                'paused' => $summary[AttemptStatus::Paused->value] ?? 0,
                'submitted' => $summary[AttemptStatus::Submitted->value] ?? 0,
                'offline' => $offline,
            ],
        ];
    }

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

        $eventCounts = DB::table('dlv_proctor_events')->whereIn('candidate_exam_id', $attempts->pluck('id'))
            ->selectRaw('candidate_exam_id, count(*) as total, max(case severity when "high" then 3 when "medium" then 2 else 1 end) as max_rank') // raw-sql-reviewed: no user input, fixed severity literals only
            ->groupBy('candidate_exam_id')->get()->keyBy('candidate_exam_id');
        $severityByRank = [3 => 'high', 2 => 'medium', 1 => 'low'];

        return array_values($attempts->map(function (CandidateExam $attempt) use ($sessions, $staleAfter, $eventCounts, $severityByRank): array {
            $session = $sessions->get($attempt->id);
            $heartbeatAgeSeconds = $session === null ? null : now()->diffInSeconds($session->last_heartbeat_at);
            $events = $eventCounts->get($attempt->id);

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
                'proctorEventCount' => $events === null ? 0 : (int) $events->total,
                'proctorHighestSeverity' => $events === null ? null : $severityByRank[(int) $events->max_rank],
            ];
        })->values()->all());
    }
}
