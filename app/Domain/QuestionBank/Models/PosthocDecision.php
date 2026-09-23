<?php

namespace App\Domain\QuestionBank\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a department decided about a question after an examination, once there were statistics for
 * it (exam phase, step 22) — never changed once written (migration 2026_10_03_000101).
 *
 * @property int $id
 * @property int $version_id
 * @property int $question_id
 * @property int $branch_id
 * @property int|null $exam_id
 * @property int $decision_type_id
 * @property float|null $observed_p
 * @property float|null $discrimination
 * @property int|null $candidates
 * @property string|null $reason
 * @property int $decided_by
 * @property Carbon $decided_at
 */
#[Fillable(['version_id', 'question_id', 'branch_id', 'exam_id', 'decision_type_id', 'observed_p', 'discrimination', 'candidates', 'reason', 'decided_by', 'decided_at'])]
final class PosthocDecision extends Model
{
    protected $table = 'qb_posthoc_decisions';

    protected function casts(): array
    {
        return [
            'observed_p' => 'float',
            'discrimination' => 'float',
            'decided_at' => 'datetime',
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
     * @return BelongsTo<PosthocDecisionType, $this>
     */
    public function decisionType(): BelongsTo
    {
        return $this->belongsTo(PosthocDecisionType::class, 'decision_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
