<?php

namespace App\Domain\Identity\Mfa;

use Illuminate\Contracts\Cache\Repository;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;

/**
 * Authenticator (TOTP) codes that work exactly once.
 *
 * Fortify's provider remembers the current time step after a successful check, so a code for the
 * next step (accepted for clock drift) could be used a second time. Here the step the code actually
 * belongs to is claimed atomically per secret, and older steps than the last one used are refused.
 * Used for every check: the MFA challenge, confirming a new authenticator and the password login.
 */
final class SingleUseTotpProvider implements TwoFactorAuthenticationProvider
{
    /** Codes one step (30 s) before or after now are accepted. */
    private const WINDOW = 1;

    public function __construct(
        private readonly Google2FA $engine,
        private readonly Repository $cache,
    ) {}

    public function generateSecretKey(int $secretLength = 16): string
    {
        return $this->engine->generateSecretKey($secretLength);
    }

    public function qrCodeUrl($companyName, $companyEmail, $secret): string
    {
        return $this->engine->getQRCodeUrl($companyName, $companyEmail, $secret);
    }

    public function verify($secret, $code): bool
    {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $key = 'mfa.totp.'.hash('sha256', (string) $secret);
        $lastStep = (int) $this->cache->get($key.'.last', 0);

        // With a previous step given, the engine returns the matching step (an int) and only looks after it.
        $step = $this->engine->verifyKeyNewer((string) $secret, $code, $lastStep, self::WINDOW);
        if (! is_int($step)) {
            return false;
        }

        $ttl = (self::WINDOW * 2 + 2) * 30;
        if (! $this->cache->add($key.'.step.'.$step, true, $ttl)) {
            return false;
        }
        $this->cache->put($key.'.last', max($step, $lastStep), $ttl);

        return true;
    }
}
