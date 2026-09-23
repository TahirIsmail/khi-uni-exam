<?php

namespace App\Domain\Delivery\Models;

use App\Domain\Candidate\Models\Candidate;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Exam\Models\Examination;
use App\Domain\Paper\Models\Paper;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One candidate's attempt at one examination: the paper they were given, the timer, and where they
 * stand. This is the record ADR-0003 rebuilds the exact exam from, on any computer.
 *
 * @property int $id
 * @property int $candidate_id
 * @property int $examination_id
 * @property int $paper_id
 * @property AttemptStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $deadline_at
 * @property Carbon|null $paused_at
 * @property int $extra_seconds
 * @property int|null $last_item_id
 * @property Carbon|null $submitted_at
 * @property string|null $submitted_by
 */
#[Fillable(['candidate_id', 'examination_id', 'paper_id', 'status', 'started_at', 'deadline_at', 'paused_at', 'extra_seconds', 'last_item_id', 'submitted_at', 'submitted_by'])]
final class CandidateExam extends Model
{
    protected $table = 'cand_candidate_exams';

    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'started_at' => 'datetime',
            'deadline_at' => 'datetime',
            'paused_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidate_id');
    }

    /**
     * @return BelongsTo<Examination, $this>
     */
    public function examination(): BelongsTo
    {
        return $this->belongsTo(Examination::class, 'examination_id');
    }

    /**
     * @return BelongsTo<Paper, $this>
     */
    public function paper(): BelongsTo
    {
        return $this->belongsTo(Paper::class, 'paper_id');
    }

    /**
     * @return HasMany<CandidatePaperItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CandidatePaperItem::class, 'candidate_exam_id')->orderBy('position');
    }

    /**
     * @return HasMany<DeliverySession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(DeliverySession::class, 'candidate_exam_id');
    }
}
