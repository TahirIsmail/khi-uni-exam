<?php

namespace App\Domain\Candidate\Queries;

use App\Domain\Candidate\Models\CandidateDevice;
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
        $pendingCounts = CandidateDevice::query()->whereIn('centre_id', $centres->pluck('id'))
            ->whereNull('approved_at')->selectRaw('centre_id, count(*) as total')->groupBy('centre_id') // raw-sql-reviewed: no user input, plain aggregate
            ->get()->pluck('total', 'centre_id');

        return array_values($centres->map(fn (Centre $centre): array => [
            'id' => $centre->id,
            'name' => $centre->name,
            'code' => $centre->code,
            'address' => $centre->address,
            'isActive' => $centre->is_active,
            'capacity' => $centre->rooms->sum('capacity'),
            'devicesPendingCount' => (int) ($pendingCounts[$centre->id] ?? 0),
            'rooms' => array_values($centre->rooms->map(fn (Room $room): array => [
                'id' => $room->id,
                'name' => $room->name,
                'capacity' => $room->capacity,
                'isActive' => $room->is_active,
            ])->all()),
        ])->all());
    }

    /**
     * @return list<array{id: int, fingerprint: string, firstSeenCandidate: string, firstSeenAt: string}>
     */
    public function pendingDevices(int $centreId): array
    {
        $devices = CandidateDevice::query()->where('centre_id', $centreId)->whereNull('approved_at')
            ->with('firstSeenAttempt.candidate')->orderBy('created_at')->get();

        return array_values($devices->map(fn (CandidateDevice $device): array => [
            'id' => $device->id,
            'fingerprint' => mb_substr($device->device_fingerprint, 0, 12),
            'firstSeenCandidate' => $device->firstSeenAttempt->candidate->name,
            'firstSeenAt' => $device->created_at->toIso8601String(),
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
