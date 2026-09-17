<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Mfa\MfaSession;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When two-factor authentication is turned on in kmu-cms, nobody can use anything until they have
 * set up an authenticator and passed a challenge in this session. Only the MFA screens, log out
 * and the CMS sign-on/logout entries stay reachable.
 */
final class RequireMfa
{
    private const EXEMPT_ROUTES = ['mfa.*', 'logout', 'sso.*'];

    public function __construct(private readonly MfaSession $mfa) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $request->routeIs(...self::EXEMPT_ROUTES) || ! $this->mfa->isRequired()) {
            return $next($request);
        }
        if ($this->mfa->hasPassed($request->session(), $user)) {
            return $next($request);
        }

        $target = $this->mfa->isEnrolled($user) ? route('mfa.challenge') : route('mfa.setup');

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'Multi-factor authentication required.', 'redirect' => $target], 403);
        }

        return redirect()->guest($target);
    }
}
