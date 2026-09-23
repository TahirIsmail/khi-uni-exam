<?php

namespace App\Domain\Delivery\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One device's sign-in to an attempt, with a heartbeat: how a second sign-in tells a crashed
 * computer (silent for a while) from one still working (ADR-0003).
 *
 * @property int $id
 * @property int $candidate_exam_id
 * @property string $token_hash
 * @property string|null $device
 * @property string|null $ip
 * @property Carbon $started_at
 * @property Carbon $last_heartbeat_at
 * @property Carbon|null $ended_at
 * @property string|null $end_reason
 */
#[Fillable(['candidate_exam_id', 'token_hash', 'device', 'ip', 'started_at', 'last_heartbeat_at', 'ended_at', 'end_reason'])]
final class DeliverySession extends Model
{
    protected $table = 'dlv_sessions';

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CandidateExam, $this>
     */
    public function candidateExam(): BelongsTo
    {
        return $this->belongsTo(CandidateExam::class, 'candidate_exam_id');
    }

    public function isAlive(int $staleAfterSeconds): bool
    {
        return $this->ended_at === null && $this->last_heartbeat_at->diffInSeconds(now()) < $staleAfterSeconds;
    }
}
