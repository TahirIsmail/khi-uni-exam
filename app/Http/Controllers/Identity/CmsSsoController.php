<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Actions\SignInFromCms;
use App\Domain\Identity\Exceptions\InvalidCmsTicket;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /sso/cms: the only entry point for staff coming from kmu-cms.
 * Excluded from CSRF verification because the signed, short-lived, single-use ticket replaces the token.
 */
final class CmsSsoController extends Controller
{
    public function __invoke(Request $request, SignInFromCms $signIn): RedirectResponse|Response
    {
        $ticket = $request->input('ticket');

        try {
            ['user' => $user, 'redirect' => $redirect] = $signIn(is_string($ticket) ? $ticket : '');
        } catch (InvalidCmsTicket $refused) {
            Log::warning('CMS single sign-on refused', [
                'reason' => $refused->reason,
                'cms_staff_id' => $refused->cmsStaffId,
                'ip' => $request->ip(),
            ]);

            return Inertia::render('auth/SsoFailed', [
                'cmsUrl' => config('services.kmu_cms.url'),
            ])->toResponse($request)->setStatusCode(403);
        }

        // Replace any session the browser already had before signing in (session fixation).
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        Log::info('CMS single sign-on', ['user_id' => $user->id, 'cms_staff_id' => $user->cms_staff_id, 'ip' => $request->ip()]);

        return redirect()->to($redirect);
    }
}
