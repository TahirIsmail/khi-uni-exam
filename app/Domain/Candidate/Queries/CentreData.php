<?php

namespace App\Domain\Candidate\Queries;

use App\Domain\Candidate\Models\Centre;
use App\Domain\Candidate\Models\Room;
use App\Models\User;

/**
 * Centres and their rooms, for the management screen and for choosing one when allocating.
 */
final class CentreData
{
    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $branchId): array
    {
        $centres = Centre::query()->where('branch_id', $branchId)->with('rooms')->orderBy('name')->get();

        return array_values($centres->map(fn (Centre $centre): array => [
            'id' => $centre->id,
            'name' => $centre->name,
            'code' => $centre->code,
            'address' => $centre->address,
            'isActive' => $centre->is_active,
            'capacity' => $centre->rooms->sum('capacity'),
            'rooms' => array_values($centre->rooms->map(fn (Room $room): array => [
                'id' => $room->id,
                'name' => $room->name,
                'capacity' => $room->capacity,
                'isActive' => $room->is_active,
            ])->all()),
        ])->all());
    }

    /**
     * @return list<array{id: int, name: string, code: string, rooms: list<array{id: int, name: string, capacity: int}>}>
     */
    public function choices(int $branchId): array
    {
        $centres = Centre::query()->where('branch_id', $branchId)->where('is_active', true)
            ->with(['rooms' => fn ($q) => $q->where('is_active', true)])->orderBy('name')->get();

        return array_values($centres->map(fn (Centre $centre): array => [
            'id' => $centre->id,
            'name' => $centre->name,
            'code' => $centre->code,
            'rooms' => array_values($centre->rooms->map(fn (Room $room): array => [
                'id' => $room->id,
                'name' => $room->name,
                'capacity' => $room->capacity,
            ])->all()),
        ])->all());
    }

    /**
     * @return array{manage: bool}
     */
    public function abilities(User $user): array
    {
        return ['manage' => $user->can('centre.manage')];
    }
}
