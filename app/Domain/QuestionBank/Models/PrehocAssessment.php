<?php

namespace App\Domain\QuestionBank\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A judgement about a question before it is used: how much thinking it needs, how hard it is, how
 * many candidates are expected to answer it correctly, and what should happen to it. The author's
 * proposal, each reviewer's values and the one consolidated row the approver settles on are all
 * kept — the consolidated values are copied onto the version when it is approved.
 *
 * @property int $id
 * @property int $version_id
 * @property int $question_id
 * @property int $branch_id
 * @property int|null $review_id
 * @property string $source author, reviewer or consolidated
 * @property int|null $cognitive_level_id
 * @property int|null $difficulty_level_id
 * @property float|null $estimated_p
 * @property int|null $decision_id
 * @property string|null $reason
 * @property bool $is_consolidated
 * @property int $assessed_by
 * @property Carbon $assessed_at
 */
#[Fillable(['version_id', 'question_id', 'branch_id', 'review_id', 'source', 'cognitive_level_id', 'difficulty_level_id', 'estimated_p', 'decision_id', 'reason', 'is_consolidated', 'assessed_by', 'assessed_at'])]
final class PrehocAssessment extends Model
{
    protected $table = 'qb_prehoc_assessments';

    protected function casts(): array
    {
        return [
            'estimated_p' => 'float',
            'is_consolidated' => 'boolean',
            'assessed_at' => 'datetime',
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
     * @return BelongsTo<PrehocDecision, $this>
     */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(PrehocDecision::class, 'decision_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    /**
     * @return BelongsTo<CognitiveLevel, $this>
     */
    public function cognitiveLevel(): BelongsTo
    {
        return $this->belongsTo(CognitiveLevel::class, 'cognitive_level_id');
    }

    /**
     * @return BelongsTo<DifficultyLevel, $this>
     */
    public function difficultyLevel(): BelongsTo
    {
        return $this->belongsTo(DifficultyLevel::class, 'difficulty_level_id');
    }
}
