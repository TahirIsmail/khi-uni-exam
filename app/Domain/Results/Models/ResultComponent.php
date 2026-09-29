<?php

namespace App\Domain\Results\Models;

use App\Domain\Exam\Models\Examination;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One part of a subject's result: the computer-based paper, the OSPE, the viva, the internal
 * assessment. What each is worth, and which half of the subject it counts towards.
 *
 * @property int $id
 * @property int $examination_id
 * @property string $code
 * @property string $name
 * @property float $max_marks
 * @property string $group theory or practical
 * @property float|null $min_pass_percentage
 * @property string $source cbt or entered
 * @property int $sort_order
 */
#[Fillable(['examination_id', 'code', 'name', 'max_marks', 'group', 'min_pass_percentage', 'source', 'sort_order'])]
final class ResultComponent extends Model
{
    protected $table = 'exm_result_components';

    protected function casts(): array
    {
        return [
            'max_marks' => 'float',
            'min_pass_percentage' => 'float',
            'sort_order' => 'integer',
        ];
    }

    /** Whether this component is the paper this system runs, rather than one somebody types in. */
    public function isPaper(): bool
    {
        return $this->source === 'cbt';
    }

    /**
     * @return BelongsTo<Examination, $this>
     */
    public function examination(): BelongsTo
    {
        return $this->belongsTo(Examination::class, 'examination_id');
    }

    /**
     * @return HasMany<ComponentMark, $this>
     */
    public function marks(): HasMany
    {
        return $this->hasMany(ComponentMark::class, 'component_id');
    }
}
