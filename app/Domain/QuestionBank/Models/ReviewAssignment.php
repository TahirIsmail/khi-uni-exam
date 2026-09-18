<?php

namespace App\Domain\QuestionBank\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A reviewer's job: review this version by this date. The author is never assigned their own
 * question, and an administrator can cancel an assignment and give it to somebody else.
 *
 * @property int $id
 * @property int $version_id
 * @property int $question_id
 * @property int $branch_id
 * @property int $reviewer_id
 * @property int|null $assigned_by
 * @property string $status
 * @property Carbon|null $due_at
 * @property Carbon $assigned_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 */
#[Fillable(['version_id', 'question_id', 'branch_id', 'reviewer_id', 'assigned_by', 'status', 'due_at', 'assigned_at', 'submitted_at', 'cancelled_at', 'cancel_reason'])]
final class ReviewAssignment extends Model
{
    protected $table = 'qb_review_assignments';

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'assigned_at' => 'datetime',
            'submitted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<QuestionVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(QuestionVersion::class, 'version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * @return HasOne<Review, $this>
     */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class, 'assignment_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at !== null && $this->due_at->isPast();
    }
}
