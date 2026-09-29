<?php

use App\Http\Controllers\Exam\AnalyticsController;
use App\Http\Controllers\Exam\ComponentMarksController;
use App\Http\Controllers\Exam\ResultsController;
use Illuminate\Support\Facades\Route;

/*
 * Results (exam phase, step 21): raw scores until the pass percentage is applied, a controller
 * approves them, and they are published. Re-keying a faulty item after the exam rescores every
 * affected candidate. Post-hoc analysis (step 22) lives in the same URL space, one step further
 * into the same examination's outcome.
 */
Route::middleware('auth')->prefix('results')->group(function () {
    Route::get('/', [ResultsController::class, 'index'])->name('results.index');

    Route::prefix('{exam}')->whereNumber('exam')->group(function () {
        Route::get('/', [ResultsController::class, 'show'])->name('results.show');
        Route::post('approve', [ResultsController::class, 'approve'])->middleware(['can:result.approve', 'throttle:30,1'])->name('results.approve');
        Route::post('publish', [ResultsController::class, 'publish'])->middleware(['can:result.publish', 'throttle:30,1'])->name('results.publish');
        Route::post('items/{item}/rekey', [ResultsController::class, 'rekey'])->whereNumber('item')->middleware(['can:result.rescore', 'throttle:30,1'])->name('results.items.rekey');

        // The parts of a professional result this system does not run: the practical, the viva and
        // the internal assessment carried from the year's class work.
        Route::middleware('can:result.components')->group(function () {
            Route::get('components', [ComponentMarksController::class, 'show'])->name('results.components');
            Route::post('components/define', [ComponentMarksController::class, 'define'])->middleware('throttle:30,1')->name('results.components.define');
            Route::post('components/marks', [ComponentMarksController::class, 'store'])->middleware('throttle:60,1')->name('results.components.store');
        });

        Route::get('analysis', [AnalyticsController::class, 'show'])->middleware('can:analytics.view')->name('results.analysis');
        Route::post('analysis/run', [AnalyticsController::class, 'run'])->middleware(['can:analytics.run', 'throttle:10,1'])->name('results.analysis.run');
        Route::post('questions/{version}/decision', [AnalyticsController::class, 'decide'])->whereNumber('version')->middleware(['can:analytics.decision.record', 'throttle:30,1'])->name('results.questions.decide');
    });
});
