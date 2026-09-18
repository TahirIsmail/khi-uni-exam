<?php

namespace App\Domain\QuestionBank\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * What one reviewer said about one version: either "request changes", which sends the question back
 * to its author, or a review with a pre-hoc decision and the item-writing checklist. Once submitted
 * it cannot be changed or deleted (database triggers); saying something else means a new review.
 *
 * @property int $id
 * @property int $assignment_id
 * @property int $version_id
 * @property int $question_id
 * @property int $branch_id
 * @property int $reviewer_id
 * @property string $outcome
 * @property int|null $decision_id
 * @property string|null $comments
 * @property list<array{code: string, pass: bool, note?: string|null}>|null $checklist
 * @property Carbon $submitted_at
 */
#[Fillable(['assignment_id', 'version_id', 'question_id', 'branch_id', 'reviewer_id', 'outcome', 'decision_id', 'comments', 'checklist', 'submitted_at'])]
final class Review extends Model
{
    protected $table = 'qb_reviews';

    protected function casts(): array
    {
        return [
            'checklist' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReviewAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ReviewAssignment::class, 'assignment_id');
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
     * @return BelongsTo<PrehocDecision, $this>
     */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(PrehocDecision::class, 'decision_id');
    }

    /**
     * @return HasOne<PrehocAssessment, $this>
     */
    public function prehoc(): HasOne
    {
        return $this->hasOne(PrehocAssessment::class, 'review_id');
    }

    public function requestedChanges(): bool
    {
        return $this->outcome === 'changes_requested';
    }
}
