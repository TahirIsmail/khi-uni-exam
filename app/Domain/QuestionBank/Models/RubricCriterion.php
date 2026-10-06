<?php

namespace App\Domain\QuestionBank\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the marking rubric for a written answer.
 *
 * @property int $id
 * @property int $version_id
 * @property int $sort_order
 * @property string $criterion
 * @property float $max_marks
 * @property string|null $guidance
 */
#[Fillable(['version_id', 'sort_order', 'criterion', 'max_marks', 'guidance'])]
final class RubricCriterion extends Model
{
    protected $table = 'qb_question_rubric_criteria';

    protected function casts(): array
    {
        return ['max_marks' => 'float'];
    }

    /**
     * @return BelongsTo<QuestionVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(QuestionVersion::class, 'version_id');
    }
}
