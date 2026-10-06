<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\Actions\CandidateGuard;
use App\Domain\Candidate\Models\Room;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Exam\Models\Examination;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A room-wide outage (the power, the network) is handled by pausing the room, which freezes every
 * running deadline in it at once, rather than each candidate's invigilator working out compensating
 * time by hand afterwards (ADR-0003).
 */
final class RoomPause
{
    public function __construct(
        private readonly CandidateGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /** @return int how many attempts were paused */
    public function pause(User $user, Examination $examination, Room $room): int
    {
        $this->guard->authorise($user, $examination, 'delivery.session_control', 'You cannot pause sessions for this course.');

        $attempts = CandidateExam::query()
            ->where('examination_id', $examination->id)
            ->where('status', AttemptStatus::InProgress)
            ->whereIn('candidate_id', DB::table('cand_candidates')->where('room_id', $room->id)->pluck('id'))
            ->get();

        foreach ($attempts as $attempt) {
            $attempt->update(['status' => AttemptStatus::Paused, 'paused_at' => now()]);
        }

        if ($attempts->isNotEmpty()) {
            $this->audit->record('candidate.room_paused', 'room', $room->id, null, ['examination_id' => $examination->id, 'count' => $attempts->count()], null, $user, $examination->branch_id);
        }

        return $attempts->count();
    }

    /** @return int how many attempts resumed */
    public function resume(User $user, Examination $examination, Room $room): int
    {
        $this->guard->authorise($user, $examination, 'delivery.session_control', 'You cannot resume sessions for this course.');

        $attempts = CandidateExam::query()
            ->where('examination_id', $examination->id)
            ->where('status', AttemptStatus::Paused)
            ->whereIn('candidate_id', DB::table('cand_candidates')->where('room_id', $room->id)->pluck('id'))
            ->get();

        foreach ($attempts as $attempt) {
            $pausedSeconds = (int) $attempt->paused_at->diffInSeconds(now());
            $attempt->update([
                'status' => AttemptStatus::InProgress,
                'paused_at' => null,
                'deadline_at' => $attempt->deadline_at?->addSeconds($pausedSeconds),
                'extra_seconds' => $attempt->extra_seconds + $pausedSeconds,
            ]);
        }

        if ($attempts->isNotEmpty()) {
            $this->audit->record('candidate.room_resumed', 'room', $room->id, null, ['examination_id' => $examination->id, 'count' => $attempts->count()], null, $user, $examination->branch_id);
        }

        return $attempts->count();
    }
}
