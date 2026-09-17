<?php

namespace App\Domain\QuestionBank\Models;

use Database\Factories\QuestionBank\QuestionOptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One option of a version: a choice (A, B, C ...), a matching answer, or a word list for a blank.
 *
 * @property int $id
 * @property int $version_id
 * @property int|null $item_id set when the option belongs to one sub-part only
 * @property string $label
 * @property string $body
 * @property bool $is_correct
 * @property float|null $weight
 * @property string|null $feedback
 * @property int $sort_order
 * @property bool $is_position_locked
 */
#[Fillable(['version_id', 'item_id', 'label', 'body', 'is_correct', 'weight', 'feedback', 'sort_order', 'is_position_locked'])]
final class QuestionOption extends Model
{
    /** @use HasFactory<QuestionOptionFactory> */
    use HasFactory;

    protected $table = 'qb_question_options';

    protected static string $factory = QuestionOptionFactory::class;

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'is_position_locked' => 'boolean',
            'weight' => 'float',
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
     * @return BelongsTo<QuestionItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(QuestionItem::class, 'item_id');
    }
}
