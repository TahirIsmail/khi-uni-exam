<?php

namespace App\Domain\Identity\Mfa;

use App\Domain\Identity\Authorization\AccessControl;
use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Multi-factor authentication for the current session (blueprint 6.2).
 *
 * MFA is required for privileged users (see Permissions::PRIVILEGED, Super Admin, break-glass)
 * and for anyone who turned on two-factor authentication themselves. It must be passed once per
 * session, whether the user signed in with a password or came from kmu-cms, and again when a
 * password-less user confirms their identity for sensitive settings.
 */
final class MfaSession
{
    private const USER_KEY = 'mfa.user_id';

    private const AT_KEY = 'mfa.passed_at';

    public function __construct(private readonly AccessControl $access) {}

    public function isRequired(User $user): bool
    {
        return $user->hasEnabledTwoFactorAuthentication() || $this->access->isPrivileged($user);
    }

    public function isEnrolled(User $user): bool
    {
        return $user->hasEnabledTwoFactorAuthentication();
    }

    public function hasPassed(Session $session, User $user): bool
    {
        return (int) $session->get(self::USER_KEY) === $user->id && $session->has(self::AT_KEY);
    }

    public function passedWithin(Session $session, User $user, int $seconds): bool
    {
        return $this->hasPassed($session, $user) && time() - (int) $session->get(self::AT_KEY) <= $seconds;
    }

    public function markPassed(Session $session, User $user): void
    {
        $session->put(self::USER_KEY, $user->id);
        $session->put(self::AT_KEY, time());
    }
}
