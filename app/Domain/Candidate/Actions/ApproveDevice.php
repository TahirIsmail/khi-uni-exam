<?php

namespace App\Domain\Candidate\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\Models\CandidateDevice;
use App\Models\User;

/**
 * The invigilator's one-time approval of a device not seen before at their centre (exam phase,
 * step 19), after which it stays approved for whoever sits at it next.
 */
final class ApproveDevice
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(User $user, CandidateDevice $device): CandidateDevice
    {
        $device->update(['approved_at' => now(), 'approved_by' => $user->id]);

        $this->audit->record('device.approved', 'candidate_device', $device->id, null, ['centre_id' => $device->centre_id], null, $user, $device->centre->branch_id);

        return $device;
    }
}
