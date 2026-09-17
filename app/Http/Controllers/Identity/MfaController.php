<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Mfa\MfaSession;
use App\Domain\Identity\Mfa\VerifyMfaCode;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;

/**
 * Setting up an authenticator and passing the MFA challenge. Works for staff who came from kmu-cms
 * and have no password here (Fortify's own two-factor routes ask for a password first).
 */
class MfaController extends Controller
{
    public function __construct(private readonly MfaSession $mfa) {}

    public function challenge(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $this->mfa->isEnrolled($user)) {
            return to_route('mfa.setup');
        }

        return Inertia::render('auth/MfaChallenge', [
            'confirming' => $request->boolean('confirm'),
        ]);
    }

    public function verify(Request $request, VerifyMfaCode $verify): RedirectResponse
    {
        $input = $request->validate([
            'code' => ['nullable', 'required_without:recovery_code', 'string', 'regex:/^\d{6}$/'],
            'recovery_code' => ['nullable', 'string', 'max:64'],
        ]);

        $verify($request->user(), $request->session(), $input['code'] ?? null, $input['recovery_code'] ?? null);

        return redirect()->intended(config('fortify.home'));
    }

    public function setup(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if ($this->mfa->isEnrolled($user)) {
            return to_route('mfa.challenge');
        }

        $started = $user->two_factor_secret !== null;

        return Inertia::render('auth/MfaSetup', [
            'required' => $this->mfa->isRequired($user),
            'started' => $started,
            'qrCodeSvg' => $started ? $user->twoFactorQrCodeSvg() : null,
            'setupKey' => $started ? decrypt($user->two_factor_secret) : null,
        ]);
    }

    public function start(Request $request, EnableTwoFactorAuthentication $enable): RedirectResponse
    {
        $user = $request->user();
        abort_if($this->mfa->isEnrolled($user), 403, 'An authenticator is already set up. Ask an administrator to reset it.');

        // A new secret each time, so a half-finished setup (for example a QR code someone else saw) is discarded.
        $enable($user, force: true);

        return to_route('mfa.setup');
    }

    public function confirm(Request $request, ConfirmTwoFactorAuthentication $confirm): RedirectResponse
    {
        $user = $request->user();
        abort_if($this->mfa->isEnrolled($user), 403);

        $input = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);

        // Marks the session as MFA-passed and audits the enrolment (RecordTwoFactorEvents).
        try {
            $confirm($user, $input['code']);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['code' => __('That code is not valid. Check the time on your phone and try the newest code.')]);
        }
        $request->session()->regenerate();
        $request->session()->flash('mfa.show_recovery_codes', true);

        return to_route('mfa.recovery-codes');
    }

    public function recoveryCodes(Request $request): Response|RedirectResponse
    {
        // Shown once, straight after setup.
        if (! $request->session()->get('mfa.show_recovery_codes')) {
            return redirect()->intended(config('fortify.home'));
        }

        return Inertia::render('auth/MfaRecoveryCodes', [
            'codes' => $request->user()->recoveryCodes(),
            'continueUrl' => $request->session()->get('url.intended', config('fortify.home')),
        ]);
    }
}
