<?php

use App\Http\Controllers\Sit\ExamController;
use App\Http\Controllers\Sit\LoginController;
use App\Http\Controllers\Sit\SubmittedController;
use Illuminate\Support\Facades\Route;

/*
 * Sitting an exam (exam phase, step 18 — see ADR-0003). A candidate signs in here with their
 * candidate number and exam PIN, never through kmu-cms: this whole group uses the `candidate` guard,
 * not `web`.
 *
 * Every limit here is a named one (AppServiceProvider): counted per route and per candidate, so a
 * candidate's autosaves never use up their submit, and a hall behind one address can sign in together.
 */
Route::prefix('sit/{exam}')->whereNumber('exam')->group(function () {
    Route::get('/', [LoginController::class, 'show'])->name('sit.login');
    Route::post('/', [LoginController::class, 'store'])->middleware('throttle:sit-login')->name('sit.login.store');

    Route::middleware(['auth:candidate', 'delivery.session'])->group(function () {
        Route::get('exam', [ExamController::class, 'show'])->name('sit.exam');
        Route::post('heartbeat', [ExamController::class, 'heartbeat'])->middleware('throttle:sit-heartbeat')->name('sit.heartbeat');
        Route::post('answer', [ExamController::class, 'answer'])->middleware('throttle:sit-answer')->name('sit.answer');
        Route::post('submit', [ExamController::class, 'submit'])->middleware('throttle:sit-submit')->name('sit.submit');
        Route::post('logout', [ExamController::class, 'logout'])->name('sit.logout');
        // Pictures in the candidate's own questions.
        Route::get('media/{media}', [ExamController::class, 'media'])->whereNumber('media')->name('sit.media');

        // Browser lockdown and centre device approval (step 19).
        Route::post('device', [ExamController::class, 'device'])->middleware('throttle:sit-device')->name('sit.device');
        Route::post('proctor-event', [ExamController::class, 'proctorEvent'])->middleware('throttle:sit-proctor-event')->name('sit.proctor-event');
    });

    Route::middleware('auth:candidate')->get('submitted', [SubmittedController::class, 'show'])->name('sit.submitted');
});
