<?php

namespace App\Domain\Marking\Models;

use App\Domain\QuestionBank\Models\RubricCriterion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rubric criterion's share of an essay mark.
 *
 * @property int $id
 * @property int $item_mark_id
 * @property int $rubric_criterion_id
 * @property float $marks_awarded
 */
#[Fillable(['item_mark_id', 'rubric_criterion_id', 'marks_awarded'])]
final class ItemMarkCriterion extends Model
{
    protected $table = 'mrk_item_mark_criteria';

    protected function casts(): array
    {
        return ['marks_awarded' => 'float'];
    }

    /**
     * @return BelongsTo<ItemMark, $this>
     */
    public function itemMark(): BelongsTo
    {
        return $this->belongsTo(ItemMark::class, 'item_mark_id');
    }

    /**
     * @return BelongsTo<RubricCriterion, $this>
     */
    public function rubricCriterion(): BelongsTo
    {
        return $this->belongsTo(RubricCriterion::class, 'rubric_criterion_id');
    }
}
