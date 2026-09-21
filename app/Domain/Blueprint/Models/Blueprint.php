<?php

namespace App\Domain\Blueprint\Models;

use App\Domain\Blueprint\Enums\BlueprintStatus;
use App\Domain\Exam\Models\Examination;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The Table of Specification of an examination, written before any question is chosen.
 *
 * @property int $id
 * @property int $examination_id
 * @property int $branch_id
 * @property BlueprintStatus $status
 * @property int|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $approved_hash
 * @property string|null $return_reason
 * @property int $created_by
 * @property int|null $updated_by
 */
#[Fillable(['examination_id', 'branch_id', 'status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'approved_hash', 'return_reason', 'created_by', 'updated_by'])]
final class Blueprint extends Model
{
    protected $table = 'exm_blueprints';

    protected function casts(): array
    {
        return [
            'status' => BlueprintStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Examination, $this>
     */
    public function examination(): BelongsTo
    {
        return $this->belongsTo(Examination::class, 'examination_id');
    }

    /**
     * @return HasMany<BlueprintRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(BlueprintRow::class, 'blueprint_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<BlueprintTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(BlueprintTarget::class, 'blueprint_id')->orderBy('id');
    }
}
