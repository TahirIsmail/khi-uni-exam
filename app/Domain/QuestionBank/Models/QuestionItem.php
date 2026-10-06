<?php

namespace App\Domain\QuestionBank\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sub-part of a version: a true/false statement, an EMQ lead-in, a matching prompt, a blank in a
 * cloze passage, or one step to put in order (its correct position is sort_order).
 *
 * @property int $id
 * @property int $version_id
 * @property int $sort_order
 * @property string $body
 * @property bool|null $is_true
 * @property int|null $correct_option_id
 * @property float|null $marks_fraction
 * @property string|null $feedback
 * @property array<string, mixed>|null $settings
 */
#[Fillable(['version_id', 'sort_order', 'body', 'is_true', 'correct_option_id', 'marks_fraction', 'feedback', 'settings'])]
final class QuestionItem extends Model
{
    protected $table = 'qb_question_items';

    protected function casts(): array
    {
        return [
            'is_true' => 'boolean',
            'marks_fraction' => 'float',
            'settings' => 'array',
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
     * @return BelongsTo<QuestionOption, $this>
     */
    public function correctOption(): BelongsTo
    {
        return $this->belongsTo(QuestionOption::class, 'correct_option_id');
    }

    /**
     * @return HasMany<QuestionAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(QuestionAnswer::class, 'item_id')->orderBy('sort_order');
    }
}
