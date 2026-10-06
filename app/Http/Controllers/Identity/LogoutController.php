<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class LogoutController extends Controller
{
    public function logout(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user('web');
        if ($user !== null) {
            $audit->record('identity.logout', 'user', $user->id, null, null, null, $user);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
