<?php

namespace App\Domain\Candidate\Models;

use App\Domain\Delivery\Models\CandidateExam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The device (browser + machine) a candidate's attempt was first seen on, at one centre (exam
 * phase, step 19). Seen once as a fingerprint, not tied to any one candidate, so an approved
 * classroom computer stays approved for whoever sits at it next.
 *
 * @property int $id
 * @property int $centre_id
 * @property string $device_fingerprint
 * @property int $first_seen_candidate_exam_id
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 */
#[Fillable(['centre_id', 'device_fingerprint', 'first_seen_candidate_exam_id', 'approved_at', 'approved_by'])]
final class CandidateDevice extends Model
{
    protected $table = 'cand_devices';

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Centre, $this>
     */
    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class, 'centre_id');
    }

    /**
     * @return BelongsTo<CandidateExam, $this>
     */
    public function firstSeenAttempt(): BelongsTo
    {
        return $this->belongsTo(CandidateExam::class, 'first_seen_candidate_exam_id');
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }
}
