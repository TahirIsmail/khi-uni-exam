<?php

namespace App\Domain\Identity\Mfa;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/**
 * Audits Fortify's two-factor events and marks the session as MFA-passed when the user has just
 * proved they hold the authenticator (password login challenge, or confirming a new authenticator).
 */
final class RecordTwoFactorEvents
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MfaSession $mfa,
        private readonly Session $session,
    ) {}

    public function handle(object $event): void
    {
        $user = $event->user ?? null;
        if (! $user instanceof User) {
            return;
        }

        match (true) {
            $event instanceof ValidTwoFactorAuthenticationCodeProvided => $this->passed($user, 'password_login_challenge'),
            $event instanceof TwoFactorAuthenticationConfirmed => $this->enrolled($user),
            $event instanceof TwoFactorAuthenticationFailed => $this->audit->record('identity.mfa.failed', 'user', $user->id, null, ['method' => 'password_login_challenge'], null, $user),
            $event instanceof TwoFactorAuthenticationDisabled => $this->audit->record('identity.mfa.disabled', 'user', $user->id, null, null, null, $user),
            $event instanceof RecoveryCodesGenerated => $this->audit->record('identity.mfa.recovery_codes_regenerated', 'user', $user->id, null, null, null, $user),
            // The code itself is never logged.
            $event instanceof RecoveryCodeReplaced => $this->audit->record('identity.mfa.recovery_code_used', 'user', $user->id, null, ['remaining' => count($user->recoveryCodes())], null, $user),
            default => null,
        };
    }

    private function passed(User $user, string $method): void
    {
        $this->mfa->markPassed($this->session, $user);
        $this->audit->record('identity.mfa.passed', 'user', $user->id, null, ['method' => $method], null, $user);
    }

    private function enrolled(User $user): void
    {
        $this->mfa->markPassed($this->session, $user);
        $this->audit->record('identity.mfa.enrolled', 'user', $user->id, null, null, null, $user);
    }
}
