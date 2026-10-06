<?php

namespace App\Domain\Delivery\Models;

use App\Domain\Delivery\Enums\ProctorDecisionType;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The committee's call against a proctoring case — never changed once written (migration
 * 2026_09_30_000202).
 *
 * @property int $id
 * @property int $candidate_exam_id
 * @property Carbon|null $covers_from
 * @property Carbon|null $covers_to
 * @property ProctorDecisionType $decision
 * @property string $reason
 * @property int $decided_by
 * @property Carbon $decided_at
 */
#[Fillable(['candidate_exam_id', 'covers_from', 'covers_to', 'decision', 'reason', 'decided_by', 'decided_at'])]
final class ProctorDecision extends Model
{
    protected $table = 'dlv_proctor_decisions';

    protected function casts(): array
    {
        return [
            'decision' => ProctorDecisionType::class,
            'covers_from' => 'datetime',
            'covers_to' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CandidateExam, $this>
     */
    public function candidateExam(): BelongsTo
    {
        return $this->belongsTo(CandidateExam::class, 'candidate_exam_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
