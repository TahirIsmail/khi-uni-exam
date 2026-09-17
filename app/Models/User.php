<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Identity\Models\UserScope;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property int|null $cms_staff_id Linked kmu-cms staff.id; set only by the SSO login, never mass-assigned.
 * @property string $name
 * @property string $email
 * @property bool $is_active
 * @property bool $is_break_glass Local emergency administrator; set only with `php artisan user:break-glass`.
 * @property Carbon|null $email_verified_at
 * @property string|null $password Null for staff who only sign in through kmu-cms.
 * @property Carbon|null $last_login_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $scopes_count
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Mirror the database default so a freshly created model has the value without a reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'cms_staff_id' => null,
        'is_active' => true,
        'is_break_glass' => false,
    ];

    /**
     * Where this user's permissions apply (see AccessControl).
     *
     * @return HasMany<UserScope, $this>
     */
    public function scopes(): HasMany
    {
        return $this->hasMany(UserScope::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'is_break_glass' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
