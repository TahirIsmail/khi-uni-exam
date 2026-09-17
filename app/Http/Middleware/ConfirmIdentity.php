<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Mfa\MfaSession;
use App\Models\User;
use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replaces the `password.confirm` middleware. Staff who sign in through kmu-cms have no password
 * here, so they confirm who they are with a fresh authenticator code instead. Password users keep
 * the normal password confirmation.
 */
final class ConfirmIdentity
{
    public function __construct(
        private readonly RequirePassword $requirePassword,
        private readonly MfaSession $mfa,
    ) {}

    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null, ?string $passwordTimeoutSeconds = null): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->password !== null) {
            return $this->requirePassword->handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
        }

        // Nothing else to confirm with yet: the account was signed in through kmu-cms and has no
        // authenticator. (Privileged users never get here without one; RequireMfa runs first.)
        if (! $this->mfa->isEnrolled($user)) {
            return $next($request);
        }

        $timeout = (int) ($passwordTimeoutSeconds ?? config('auth.password_timeout', 10800));
        if ($this->mfa->passedWithin($request->session(), $user, $timeout)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'Identity confirmation required.'], 423);
        }

        return redirect()->guest(route('mfa.challenge', ['confirm' => 1]));
    }
}
