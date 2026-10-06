<?php

namespace App\Http\Middleware;

use App\Domain\Delivery\Models\DeliverySession;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A candidate stays signed in to the exam only while their own device's session is the one still
 * open (ADR-0003): once it has been replaced by a newer sign-in or ended by an invigilator, the
 * cookie alone is not enough — they are signed out here, on the very next request from this device.
 */
final class EnsureDeliverySessionActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $sessionId = $request->session()->get('dlv_session_id');
        $session = is_int($sessionId) ? DeliverySession::find($sessionId) : null;

        if ($session === null || $session->ended_at !== null) {
            Auth::guard('candidate')->logout();
            $request->session()->forget('dlv_session_id');

            $exam = $request->route('exam');

            return redirect()->route('sit.login', $exam instanceof Model ? $exam->getAttribute('sit_code') : $exam)
                ->withErrors(['session' => 'You have been signed out: this exam is now open on another computer, or an invigilator ended this session.']);
        }

        return $next($request);
    }
}
