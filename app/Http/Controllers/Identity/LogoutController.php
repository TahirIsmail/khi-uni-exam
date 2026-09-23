<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Exceptions\InvalidCmsTicket;
use App\Domain\Identity\Sso\CmsTicketVerifier;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single logout with kmu-cms, in both directions:
 *
 * - POST /logout (here): end this session, then send the browser to kmu-cms to log out there too.
 *   kmu-cms comes back through GET /sso/logout and finally shows its login page.
 * - GET /sso/logout?token= (from kmu-cms): a signed 60-second logout token; end this session and
 *   return to the kmu-cms login page. An invalid token changes nothing.
 */
final class LogoutController extends Controller
{
    public function logout(Request $request, AuditLogger $audit): Response
    {
        $this->endSession($request, $audit, 'kmu-assess');

        return Inertia::location($this->cmsUrl('/site/logout?from=kmu-assess'));
    }

    public function fromCms(Request $request, CmsTicketVerifier $verifier, AuditLogger $audit): RedirectResponse
    {
        $token = $request->query('token');

        try {
            $verifier->verifyLogout(is_string($token) ? $token : '', time());
        } catch (InvalidCmsTicket) {
            abort(403);
        }

        $this->endSession($request, $audit, 'kmu-cms');

        return redirect()->away($this->cmsUrl('/site/login'));
    }

    private function endSession(Request $request, AuditLogger $audit, string $startedIn): void
    {
        $user = $request->user('web');
        if ($user !== null) {
            $audit->record('identity.logout', 'user', $user->id, null, ['started_in' => $startedIn], null, $user);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function cmsUrl(string $path): string
    {
        return rtrim((string) config('services.kmu_cms.url'), '/').$path;
    }
}
