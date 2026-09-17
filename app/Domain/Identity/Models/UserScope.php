<?php

namespace App\Domain\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A row of sec_user_scopes. Written only by ManageUserScopes (which checks branches and audits).
 *
 * @property int $id
 * @property int $user_id
 * @property string $scope_type
 * @property int|null $scope_id
 * @property int|null $granted_by
 * @property Carbon|null $created_at
 */
final class UserScope extends Model
{
    protected $table = 'sec_user_scopes';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'scope_id' => 'integer',
            'granted_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
