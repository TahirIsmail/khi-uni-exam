<?php

namespace App\Domain\QuestionBank\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An answer the candidate types: a word or phrase for a short answer or a blank, or a number with a
 * tolerance. Several rows mean several accepted answers, each worth a share of the marks.
 *
 * @property int $id
 * @property int $version_id
 * @property int|null $item_id
 * @property string $match_mode exact, contains, regex, numeric
 * @property string|null $answer_text
 * @property bool $case_sensitive
 * @property float|null $numeric_value
 * @property float|null $tolerance
 * @property string $tolerance_type
 * @property string|null $unit
 * @property float $marks_fraction
 * @property string|null $feedback
 * @property int $sort_order
 */
#[Fillable(['version_id', 'item_id', 'match_mode', 'answer_text', 'case_sensitive', 'numeric_value', 'tolerance', 'tolerance_type', 'unit', 'marks_fraction', 'feedback', 'sort_order'])]
final class QuestionAnswer extends Model
{
    protected $table = 'qb_question_answers';

    protected function casts(): array
    {
        return [
            'case_sensitive' => 'boolean',
            'numeric_value' => 'float',
            'tolerance' => 'float',
            'marks_fraction' => 'float',
        ];
    }

    /**
     * @return BelongsTo<QuestionVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(QuestionVersion::class, 'version_id');
    }
}
