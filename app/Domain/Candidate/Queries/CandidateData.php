<?php

namespace App\Domain\Candidate\Queries;

use App\Domain\Candidate\Enums\CandidateStatus;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Exam\Models\Examination;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Models\User;

/**
 * The candidate roster of one examination, and what a person may do to it.
 */
final class CandidateData
{
    public function __construct(private readonly AccessControl $access) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Examination $examination): array
    {
        $candidates = Candidate::query()->where('examination_id', $examination->id)
            ->with(['centre', 'room'])->orderBy('candidate_no')->get();

        return array_values($candidates->map(fn (Candidate $candidate): array => [
            'id' => $candidate->id,
            'candidateNo' => $candidate->candidate_no,
            'name' => $candidate->name,
            'rollNo' => $candidate->roll_no,
            'cnic' => $candidate->cnic,
            'email' => $candidate->email,
            'phone' => $candidate->phone,
            'status' => $candidate->status->value,
            'statusLabel' => $candidate->status->label(),
            'centre' => $candidate->centre?->name,
            'room' => $candidate->room?->name,
            'seatNo' => $candidate->seat_no,
            'extraTimeMinutes' => $candidate->extra_time_minutes,
            'extraTimeReason' => $candidate->extra_time_reason,
            'hasPin' => $candidate->pin_hash !== null,
            'checkedInAt' => $candidate->checked_in_at?->toIso8601String(),
        ])->all());
    }

    /**
     * @return array{total: int, enrolled: int, allocated: int, checkedIn: int}
     */
    public function summary(Examination $examination): array
    {
        $counts = Candidate::query()->where('examination_id', $examination->id)
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'); // raw-sql-reviewed: a fixed literal, no user input

        return [
            'total' => (int) $counts->sum(),
            'enrolled' => (int) ($counts[CandidateStatus::Enrolled->value] ?? 0),
            'allocated' => (int) ($counts[CandidateStatus::Allocated->value] ?? 0),
            'checkedIn' => (int) ($counts[CandidateStatus::CheckedIn->value] ?? 0),
        ];
    }

    /**
     * @return array{manage: bool, allocate: bool, checkin: bool, extraTime: bool}
     */
    public function abilities(User $user, Examination $examination): array
    {
        $target = new ScopeTarget($examination->branch_id, $examination->programme_id, $examination->professional_id, $examination->course_id);

        return [
            'manage' => $this->access->allows($user, 'candidate.manage', $target),
            'allocate' => $this->access->allows($user, 'centre.allocate', $target),
            'checkin' => $this->access->allows($user, 'candidate.checkin', $target),
            'extraTime' => $this->access->allows($user, 'candidate.extra_time', $target),
        ];
    }
}
