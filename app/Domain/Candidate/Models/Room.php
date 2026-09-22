<?php

namespace App\Domain\Candidate\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A room inside an exam centre, with how many candidates it holds.
 *
 * @property int $id
 * @property int $centre_id
 * @property string $name
 * @property int $capacity
 * @property bool $is_active
 */
#[Fillable(['centre_id', 'name', 'capacity', 'is_active'])]
final class Room extends Model
{
    protected $table = 'cand_rooms';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Centre, $this>
     */
    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class, 'centre_id');
    }
}
