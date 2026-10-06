<?php

namespace App\Domain\Candidate\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An exam centre: a place candidates sit an exam, on one campus. Reused across every examination
 * held there.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $name
 * @property string $code
 * @property string|null $address
 * @property bool $is_active
 * @property int $created_by
 * @property int|null $updated_by
 */
#[Fillable(['branch_id', 'name', 'code', 'address', 'is_active', 'created_by', 'updated_by'])]
final class Centre extends Model
{
    protected $table = 'cand_centres';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'centre_id')->orderBy('name');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
