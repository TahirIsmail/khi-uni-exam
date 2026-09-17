<?php

namespace App\Domain\Identity\Sso;

use App\Domain\Identity\Exceptions\InvalidCmsTicket;

/**
 * Verifies the signature and claims of a sign-on ticket issued by kmu-cms.
 *
 * Format: base64url(JSON claims) "." base64url(HMAC-SHA256(first part, secret)).
 * Claims: iss "kmu-cms", aud "kmu-assess", sub (CMS staff id), iat, exp (iat + at most 60 s),
 * jti (64 hex characters, single use), redirect (optional path inside this app), branch (the campus
 * selected in kmu-cms, or null).
 * Checking that the jti has not been used before is done by the caller, which owns storage.
 */
final class CmsTicketVerifier
{
    private const MAX_TICKET_LENGTH = 2048;

    public function __construct(
        private readonly string $secret,
        private readonly int $ttlSeconds,
        private readonly int $clockSkewSeconds,
    ) {}

    /**
     * @return array{sub: int, jti: string, exp: int, redirect: string, branch: int|null}
     */
    public function verify(string $ticket, int $now): array
    {
        $claims = $this->signedClaims($ticket, $now);

        // A logout token must never work as a sign-in ticket.
        if (array_key_exists('purpose', $claims)) {
            throw new InvalidCmsTicket('wrong_purpose');
        }

        $jti = $claims['jti'] ?? null;
        if (! is_string($jti) || preg_match('/^[a-f0-9]{64}$/', $jti) !== 1) {
            throw new InvalidCmsTicket('invalid_ticket_id');
        }

        $sub = $claims['sub'] ?? null;
        if (! is_int($sub) || $sub < 1) {
            throw new InvalidCmsTicket('invalid_subject');
        }

        $branch = $claims['branch'] ?? null;

        return [
            'sub' => $sub,
            'jti' => $jti,
            'exp' => (int) $claims['exp'],
            'redirect' => self::safeRedirect($claims['redirect'] ?? null),
            // The campus selected in kmu-cms; null there means "All Branches".
            'branch' => (is_int($branch) && $branch > 0) ? $branch : null,
        ];
    }

    /**
     * A single-logout token from kmu-cms: signed, short-lived, purpose "logout". It names no user;
     * it only ends the session of the browser that carries it.
     */
    public function verifyLogout(string $token, int $now): void
    {
        if (($this->signedClaims($token, $now)['purpose'] ?? null) !== 'logout') {
            throw new InvalidCmsTicket('wrong_purpose');
        }
    }

    /**
     * Signature, issuer, audience and lifetime shared by sign-in tickets and logout tokens.
     *
     * @return array<string, mixed>
     */
    private function signedClaims(string $ticket, int $now): array
    {
        $key = base64_decode($this->secret, true);
        if ($key === false || strlen($key) < 32) {
            throw new InvalidCmsTicket('sso_secret_not_configured');
        }

        if ($ticket === '' || strlen($ticket) > self::MAX_TICKET_LENGTH || substr_count($ticket, '.') !== 1) {
            throw new InvalidCmsTicket('malformed');
        }

        [$payload, $signature] = explode('.', $ticket);
        $expected = self::base64UrlEncode(hash_hmac('sha256', $payload, $key, true));

        if (! hash_equals($expected, $signature)) {
            throw new InvalidCmsTicket('bad_signature');
        }

        $claims = json_decode((string) self::base64UrlDecode($payload), true);
        if (! is_array($claims)) {
            throw new InvalidCmsTicket('malformed');
        }

        if (($claims['iss'] ?? null) !== 'kmu-cms' || ($claims['aud'] ?? null) !== 'kmu-assess') {
            throw new InvalidCmsTicket('wrong_issuer_or_audience');
        }

        $iat = $claims['iat'] ?? null;
        $exp = $claims['exp'] ?? null;
        if (! is_int($iat) || ! is_int($exp) || $exp <= $iat || $exp - $iat > $this->ttlSeconds) {
            throw new InvalidCmsTicket('invalid_lifetime');
        }
        if ($iat > $now + $this->clockSkewSeconds) {
            throw new InvalidCmsTicket('issued_in_future');
        }
        if ($now > $exp + $this->clockSkewSeconds) {
            throw new InvalidCmsTicket('expired');
        }

        return $claims;
    }

    /**
     * Only a relative path inside this app is accepted, so the ticket cannot send the user to another site.
     */
    public static function safeRedirect(mixed $path): string
    {
        if (! is_string($path) || strlen($path) > 200 || preg_match('#^/(?![/\\\\])[A-Za-z0-9\-._~/?=&%]*$#', $path) !== 1) {
            return '/dashboard';
        }

        return $path;
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string|false
    {
        if (preg_match('/^[A-Za-z0-9\-_]*$/', $value) !== 1) {
            return false;
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
