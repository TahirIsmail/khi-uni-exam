<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Sign out a user whose account has been deactivated, even mid-session.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // There is no login screen here; staff sign in again through kmu-cms.
            return redirect()->away(rtrim((string) config('services.kmu_cms.url'), '/').'/site/login');
        }

        return $next($request);
    }
}
