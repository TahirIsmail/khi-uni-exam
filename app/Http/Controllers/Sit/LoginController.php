<?php

namespace App\Http\Controllers\Sit;

use App\Domain\Delivery\Actions\StartOrResumeAttempt;
use App\Domain\Exam\Models\Examination;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Signing in to sit an exam: a candidate number and PIN, not a kmu-cms account (ADR-0003).
 */
class LoginController extends Controller
{
    public function show(Examination $exam): Response
    {
        return Inertia::render('sit/Login', [
            'examination' => [
                'id' => $exam->id,
                'title' => $exam->title,
                'reference' => $exam->public_ref,
            ],
        ]);
    }

    public function store(Request $request, Examination $exam, StartOrResumeAttempt $start): RedirectResponse
    {
        $input = $request->validate([
            'candidate_no' => ['required', 'string', 'max:30'],
            'pin' => ['required', 'string', 'max:10'],
        ]);

        $result = $start($exam, $input['candidate_no'], $input['pin'], $request->ip(), $request->userAgent());

        Auth::guard('candidate')->login($result['attempt']->candidate);

        if ($result['outcome'] === 'submitted') {
            return to_route('sit.submitted', $exam);
        }

        $request->session()->put('dlv_session_id', $result['session']->id);

        return to_route('sit.exam', $exam);
    }
}
