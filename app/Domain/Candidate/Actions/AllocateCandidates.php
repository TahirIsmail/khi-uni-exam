<?php

namespace App\Domain\Candidate\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\Enums\CandidateStatus;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Candidate\Models\Centre;
use App\Domain\Candidate\Models\Room;
use App\Domain\Exam\Models\Examination;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Seats candidates: automatically, filling a centre's rooms in order up to their capacity, or one at
 * a time by hand. A candidate already checked in keeps their seat — moving them after that is a
 * check-in day correction, not an allocation, and is refused here on purpose.
 */
final class AllocateCandidates
{
    public function __construct(
        private readonly CandidateGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Fills the centre's active rooms, in name order, with every candidate not yet allocated.
     * A candidate already allocated elsewhere keeps their seat: run it again for the rest.
     *
     * @return array{allocated: int, unseated: int}
     */
    public function auto(User $user, Examination $examination, Centre $centre): array
    {
        $this->guard->authorise($user, $examination, 'centre.allocate', 'You cannot allocate candidates for this course.');
        if ($centre->branch_id !== $examination->branch_id) {
            throw ValidationException::withMessages(['centre' => 'That centre is on another campus.']);
        }

        return DB::transaction(function () use ($user, $examination, $centre): array {
            $rooms = Room::query()->where('centre_id', $centre->id)->where('is_active', true)->orderBy('name')->get();
            $taken = Candidate::query()->whereIn('room_id', $rooms->pluck('id'))->whereNotNull('room_id')
                ->select('room_id')->get()->countBy('room_id');

            $waiting = Candidate::query()->where('examination_id', $examination->id)
                ->where('status', CandidateStatus::Enrolled->value)->orderBy('candidate_no')->get();

            $allocated = 0;
            $roomIndex = 0;
            $rooms = $rooms->values();

            foreach ($waiting as $candidate) {
                // Skip full rooms, in order, until one has room or none is left.
                while ($roomIndex < $rooms->count() && ($taken[$rooms[$roomIndex]->id] ?? 0) >= $rooms[$roomIndex]->capacity) {
                    $roomIndex++;
                }
                if ($roomIndex >= $rooms->count()) {
                    break;
                }
                $room = $rooms[$roomIndex];

                $candidate->update([
                    'centre_id' => $centre->id,
                    'room_id' => $room->id,
                    'status' => CandidateStatus::Allocated->value,
                    'allocated_by' => $user->id,
                    'allocated_at' => now(),
                    'updated_by' => $user->id,
                ]);
                $taken[$room->id] = ($taken[$room->id] ?? 0) + 1;
                $allocated++;
            }

            if ($allocated > 0) {
                $this->audit->record('candidate.allocated', 'examination', $examination->id, null, ['centre_id' => $centre->id, 'count' => $allocated], null, $user, $examination->branch_id);
            }

            return ['allocated' => $allocated, 'unseated' => $waiting->count() - $allocated];
        });
    }

    /** One candidate, chosen or moved by hand. */
    public function one(User $user, Examination $examination, Candidate $candidate, Room $room, ?string $seatNo): Candidate
    {
        $this->guard->authorise($user, $examination, 'centre.allocate', 'You cannot allocate candidates for this course.');
        if ($candidate->examination_id !== $examination->id) {
            throw new AuthorizationException('That candidate is not on this examination.');
        }
        if ($candidate->status === CandidateStatus::CheckedIn) {
            throw ValidationException::withMessages(['candidate' => 'This candidate has already checked in; their seat cannot be changed here.']);
        }
        $centre = $room->centre;
        if ($centre === null || $centre->branch_id !== $examination->branch_id) {
            throw ValidationException::withMessages(['room' => 'That room is not on this campus.']);
        }

        $before = ['centre_id' => $candidate->centre_id, 'room_id' => $candidate->room_id, 'seat_no' => $candidate->seat_no];

        $candidate->update([
            'centre_id' => $room->centre_id,
            'room_id' => $room->id,
            'seat_no' => $seatNo,
            'status' => CandidateStatus::Allocated->value,
            'allocated_by' => $user->id,
            'allocated_at' => now(),
            'updated_by' => $user->id,
        ]);

        $this->audit->record('candidate.allocated', 'candidate', $candidate->id, $before, ['centre_id' => $room->centre_id, 'room_id' => $room->id, 'seat_no' => $seatNo], null, $user, $examination->branch_id);

        return $candidate;
    }
}
