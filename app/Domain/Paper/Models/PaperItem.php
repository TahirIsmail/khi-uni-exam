<?php

namespace App\Domain\Paper\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question of a paper: a version of a question, pinned when it was chosen, and the blueprint row
 * it was chosen for.
 *
 * @property int $id
 * @property int $paper_id
 * @property int $position
 * @property int $question_id
 * @property int $version_id
 * @property int $question_type_id
 * @property int $row_node_id
 * @property string|null $section_name
 * @property float $marks
 * @property bool $is_locked
 * @property string $source auto|manual
 * @property int $picked_by
 */
#[Fillable(['paper_id', 'position', 'question_id', 'version_id', 'question_type_id', 'row_node_id', 'section_name', 'marks', 'is_locked', 'source', 'picked_by'])]
final class PaperItem extends Model
{
    protected $table = 'exm_paper_items';

    protected function casts(): array
    {
        return [
            'marks' => 'float',
            'is_locked' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Paper, $this>
     */
    public function paper(): BelongsTo
    {
        return $this->belongsTo(Paper::class, 'paper_id');
    }

    /** The blueprint row this item was chosen for, as a key: topic, type, marks and section. */
    public function slotKey(): string
    {
        return PaperSlot::keyOf($this->row_node_id, $this->question_type_id, $this->marks, $this->section_name);
    }
}
