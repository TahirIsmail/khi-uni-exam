<?php

namespace App\Domain\Identity\Mfa;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * Checks an authenticator code or a one-time recovery code for the signed-in user and marks the
 * session. Codes cannot be replayed (Fortify caches used TOTP codes; a recovery code is replaced
 * as soon as it is used). Every attempt is audited without the code itself.
 */
final class VerifyMfaCode
{
    public function __construct(
        private readonly TwoFactorAuthenticationProvider $provider,
        private readonly MfaSession $mfa,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $user, Session $session, ?string $code, ?string $recoveryCode): void
    {
        if (! $this->mfa->isEnrolled($user) || $user->two_factor_secret === null) {
            throw ValidationException::withMessages(['code' => __('Two-factor authentication is not set up for this account.')]);
        }

        $method = null;
        if ($recoveryCode !== null && $recoveryCode !== '') {
            $match = collect($user->recoveryCodes())->first(fn (string $stored): bool => hash_equals($stored, $recoveryCode));
            if ($match !== null) {
                $user->replaceRecoveryCode($match);
                $method = 'recovery_code';
            }
        } elseif ($code !== null && $code !== '' && $this->provider->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $code)) {
            $method = 'authenticator';
        }

        if ($method === null) {
            $this->audit->record('identity.mfa.failed', 'user', $user->id, null, ['method' => $recoveryCode ? 'recovery_code' : 'authenticator'], null, $user);
            $field = $recoveryCode ? 'recovery_code' : 'code';

            throw ValidationException::withMessages([$field => __('That code is not valid.')]);
        }

        $session->regenerate();
        $this->mfa->markPassed($session, $user);
        $this->audit->record('identity.mfa.passed', 'user', $user->id, null, ['method' => $method], null, $user);
    }
}
