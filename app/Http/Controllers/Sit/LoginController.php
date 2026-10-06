<?php

namespace App\Http\Controllers\Sit;

use App\Domain\Delivery\Actions\StartOrResumeAttempt;
use App\Domain\Exam\Models\Examination;
use App\Domain\Paper\Enums\PaperStatus;
use App\Domain\Paper\Models\Paper;
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
                'code' => $exam->sit_code,
                'title' => $exam->title,
                'reference' => $exam->public_ref,
            ],
            'opening' => $this->opening($exam),
        ]);
    }

    /**
     * Whether the examination can be started now, so the page says so before anybody types a PIN:
     * not open yet (with the time it opens), closed, or waiting for its paper to be published.
     *
     * @return array{state: 'open'|'not_yet'|'closed'|'not_published', opensAt: string|null, closesAt: string|null, secondsToOpen: int|null}
     */
    private function opening(Examination $exam): array
    {
        $zone = (string) config('exam.timezone');
        $opens = $exam->starts_at?->setTimezone($zone);
        $closes = $exam->closes_at?->setTimezone($zone);
        $published = Paper::query()->where('examination_id', $exam->id)->where('status', PaperStatus::Published)->exists();

        $state = match (true) {
            $exam->closes_at !== null && now()->greaterThanOrEqualTo($exam->closes_at) => 'closed',
            $exam->closes_at !== null && $exam->starts_at !== null && now()->lessThan($exam->starts_at) => 'not_yet',
            ! $published => 'not_published',
            default => 'open',
        };

        return [
            'state' => $state,
            'opensAt' => $opens?->format('l j F Y, g:i A'),
            'closesAt' => $closes?->format('l j F Y, g:i A'),
            'secondsToOpen' => $state === 'not_yet' ? (int) now()->diffInSeconds($exam->starts_at) : null,
        ];
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
