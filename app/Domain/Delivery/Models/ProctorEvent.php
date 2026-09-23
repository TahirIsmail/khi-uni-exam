<?php

namespace App\Domain\Delivery\Models;

use App\Domain\Delivery\Enums\ProctorEventType;
use App\Domain\Delivery\Enums\ProctorSeverity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing the candidate's browser reported: leaving fullscreen, switching tabs, or trying to copy,
 * paste, right-click, print or open developer tools. Append-only (migration 2026_09_30_000202).
 *
 * @property int $id
 * @property int $candidate_exam_id
 * @property int|null $session_id
 * @property ProctorEventType $type
 * @property ProctorSeverity $severity
 * @property array<string, mixed>|null $detail
 * @property Carbon $occurred_at
 */
#[Fillable(['candidate_exam_id', 'session_id', 'type', 'severity', 'detail', 'occurred_at'])]
final class ProctorEvent extends Model
{
    protected $table = 'dlv_proctor_events';

    protected function casts(): array
    {
        return [
            'type' => ProctorEventType::class,
            'severity' => ProctorSeverity::class,
            'detail' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CandidateExam, $this>
     */
    public function candidateExam(): BelongsTo
    {
        return $this->belongsTo(CandidateExam::class, 'candidate_exam_id');
    }
}
