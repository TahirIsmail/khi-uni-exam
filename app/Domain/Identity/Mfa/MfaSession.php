<?php

namespace App\Domain\Identity\Mfa;

use App\Models\User;
use App\Support\Cms\CmsSettings;
use Illuminate\Contracts\Session\Session;

/**
 * Multi-factor authentication for the current session (blueprint 6.2).
 *
 * A Super Admin turns it on or off for everyone in kmu-cms (off by default). When on, every staff
 * member must set up an authenticator app and pass a challenge once per session after arriving
 * from kmu-cms.
 */
final class MfaSession
{
    private const USER_KEY = 'mfa.user_id';

    private const AT_KEY = 'mfa.passed_at';

    public function __construct(private readonly CmsSettings $settings) {}

    public function isRequired(): bool
    {
        return $this->settings->mfaEnabled();
    }

    public function isEnrolled(User $user): bool
    {
        return $user->hasEnabledTwoFactorAuthentication();
    }

    public function hasPassed(Session $session, User $user): bool
    {
        return (int) $session->get(self::USER_KEY) === $user->id && $session->has(self::AT_KEY);
    }

    public function markPassed(Session $session, User $user): void
    {
        $session->put(self::USER_KEY, $user->id);
        $session->put(self::AT_KEY, time());
    }
}
