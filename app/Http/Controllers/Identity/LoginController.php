<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\ActiveBranch;
use App\Domain\Identity\ActiveIntake;
use App\Domain\Identity\Models\CmsStaff;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff sign in here with their email and password (this app runs without kmu-cms). The account
 * is the staff member set up under Setup → Staff; a staff member switched off there cannot sign in.
 */
final class LoginController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('auth/Login');
    }

    public function store(Request $request, AuditLogger $audit, ActiveBranch $activeBranch, ActiveIntake $activeIntake): RedirectResponse
    {
        $input = $request->validate([
            'email' => ['required', 'string', 'email', 'max:200'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $user = User::query()->where('email', mb_strtolower(trim($input['email'])))->first();
        $staff = $user?->cms_staff_id === null ? null : CmsStaff::query()->find($user->cms_staff_id);

        if ($user === null || $user->password === null || ! Hash::check($input['password'], $user->password)) {
            Log::warning('Sign-in refused', ['email' => $input['email'], 'ip' => $request->ip()]);

            throw ValidationException::withMessages(['email' => 'The email or password is wrong.']);
        }
        if (! $user->is_active || $staff === null || ! $staff->is_active) {
            throw ValidationException::withMessages(['email' => 'This account is switched off. Ask the administrator.']);
        }

        // A new session id on sign-in (session fixation); the page they were going to is kept.
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();
        $branchId = $activeBranch->open($user, $staff->branch_id === null ? null : (int) $staff->branch_id);
        $activeIntake->remember(null);

        $audit->record('identity.login', 'user', $user->id, null, null, null, $user, $branchId);

        return redirect()->intended(route('dashboard'));
    }
}
