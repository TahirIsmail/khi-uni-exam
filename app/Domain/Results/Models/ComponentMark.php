<?php

namespace App\Domain\Results\Models;

use App\Domain\Candidate\Models\Candidate;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one candidate was given for one component — their OSPE, their viva, their internal
 * assessment. Entered by the department, never computed here.
 *
 * @property int $id
 * @property int $component_id
 * @property int $candidate_id
 * @property float $marks
 * @property int $entered_by
 * @property Carbon $entered_at
 */
#[Fillable(['component_id', 'candidate_id', 'marks', 'entered_by', 'entered_at'])]
final class ComponentMark extends Model
{
    protected $table = 'exm_component_marks';

    protected function casts(): array
    {
        return [
            'marks' => 'float',
            'entered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ResultComponent, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(ResultComponent::class, 'component_id');
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidate_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
