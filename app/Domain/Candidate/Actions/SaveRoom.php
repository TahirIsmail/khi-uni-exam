<?php

namespace App\Domain\Candidate\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\Models\Centre;
use App\Domain\Candidate\Models\Room;
use App\Domain\Identity\Authorization\AccessControl;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a room inside a centre, and how many candidates it holds.
 */
final class SaveRoom
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, capacity: int, is_active: bool}  $input
     */
    public function __invoke(User $user, Centre $centre, ?Room $room, array $input): Room
    {
        if (! $this->access->has($user, 'centre.manage')) {
            throw new AuthorizationException('You cannot manage exam centres.');
        }
        if ($room !== null && $room->centre_id !== $centre->id) {
            throw new AuthorizationException('That room belongs to another centre.');
        }

        $exists = Room::query()->where('centre_id', $centre->id)->where('name', $input['name'])
            ->when($room !== null, fn ($q) => $q->whereKeyNot($room->id))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => 'A room with this name already exists in this centre.']);
        }

        return DB::transaction(function () use ($user, $centre, $room, $input): Room {
            $before = $room?->only(['name', 'capacity', 'is_active']);

            if ($room === null) {
                $room = Room::query()->create([...$input, 'centre_id' => $centre->id]);
                $this->audit->record('room.created', 'room', $room->id, null, $input, null, $user, $centre->branch_id);
            } else {
                $room->update($input);
                $this->audit->record('room.updated', 'room', $room->id, $before, $input, null, $user, $centre->branch_id);
            }

            return $room;
        });
    }
}
