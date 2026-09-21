<?php

namespace App\Domain\Blueprint\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a blueprint: this many questions of this type, worth this much each, from this topic
 * (or heading — its subtopics count too), optionally in a section.
 *
 * @property int $id
 * @property int $blueprint_id
 * @property int $sort_order
 * @property int|null $section_id
 * @property int $node_id
 * @property int|null $discipline_id
 * @property int $question_type_id
 * @property int $question_count
 * @property float $marks_each
 */
#[Fillable(['blueprint_id', 'sort_order', 'section_id', 'node_id', 'discipline_id', 'question_type_id', 'question_count', 'marks_each'])]
final class BlueprintRow extends Model
{
    protected $table = 'exm_blueprint_rows';

    protected function casts(): array
    {
        return ['marks_each' => 'float'];
    }

    /**
     * @return BelongsTo<Blueprint, $this>
     */
    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(Blueprint::class, 'blueprint_id');
    }

    public function marks(): float
    {
        return round($this->question_count * $this->marks_each, 2);
    }
}
