<?php

namespace App\Domain\Exam\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A part of a paper — Section A multiple choice, Section B short answer. Optional.
 *
 * @property int $id
 * @property int $examination_id
 * @property string $name
 * @property int $sort_order
 */
#[Fillable(['examination_id', 'name', 'sort_order'])]
final class Section extends Model
{
    protected $table = 'exm_sections';

    /**
     * @return BelongsTo<Examination, $this>
     */
    public function examination(): BelongsTo
    {
        return $this->belongsTo(Examination::class, 'examination_id');
    }
}
