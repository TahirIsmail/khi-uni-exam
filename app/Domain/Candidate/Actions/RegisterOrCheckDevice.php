<?php

namespace App\Domain\Candidate\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\Models\CandidateDevice;
use App\Domain\Delivery\Models\CandidateExam;
use App\Support\Cms\CmsSettings;
use Illuminate\Support\Facades\DB;

/**
 * Centre device approval (exam phase, step 19): a device (browser + machine) not seen before at
 * this candidate's centre is flagged and needs an invigilator's one-time approval before the
 * attempt may proceed. Once approved, the device stays approved for whoever sits at it next —
 * approval is of the machine, not of one candidate.
 */
final class RegisterOrCheckDevice
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CmsSettings $settings,
    ) {}

    /**
     * @return array{status: 'approved'|'pending'|'skipped', device: ?CandidateDevice}
     */
    public function __invoke(CandidateExam $attempt, string $rawFingerprint): array
    {
        // Switched on under Setup (and not switched off for the whole server).
        if (! config('exam.delivery.device_approval_required') || ! $this->settings->deviceApproval()) {
            return ['status' => 'skipped', 'device' => null];
        }

        $centreId = $attempt->candidate->centre_id;
        if ($centreId === null) {
            // Not yet allocated to a centre: nothing to check a device against.
            return ['status' => 'skipped', 'device' => null];
        }

        $fingerprint = hash('sha256', $rawFingerprint);

        return DB::transaction(function () use ($attempt, $centreId, $fingerprint): array {
            $device = CandidateDevice::query()
                ->where('centre_id', $centreId)
                ->where('device_fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($device === null) {
                $device = CandidateDevice::query()->create([
                    'centre_id' => $centreId,
                    'device_fingerprint' => $fingerprint,
                    'first_seen_candidate_exam_id' => $attempt->id,
                ]);
                $this->audit->record('device.seen', 'candidate_device', $device->id, null, ['centre_id' => $centreId, 'candidate_exam_id' => $attempt->id], null, null, $attempt->examination->branch_id);
            }

            return ['status' => $device->isApproved() ? 'approved' : 'pending', 'device' => $device];
        });
    }
}
