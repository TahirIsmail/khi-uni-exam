<?php

use App\Http\Controllers\Exam\ResultsController;
use Illuminate\Support\Facades\Route;

/*
 * Results (exam phase, step 21): raw scores until the pass percentage is applied, a controller
 * approves them, and they are published. Re-keying a faulty item after the exam rescores every
 * affected candidate.
 */
Route::middleware('auth')->prefix('results')->group(function () {
    Route::get('/', [ResultsController::class, 'index'])->name('results.index');

    Route::prefix('{exam}')->whereNumber('exam')->group(function () {
        Route::get('/', [ResultsController::class, 'show'])->name('results.show');
        Route::post('approve', [ResultsController::class, 'approve'])->middleware(['can:result.approve', 'throttle:30,1'])->name('results.approve');
        Route::post('publish', [ResultsController::class, 'publish'])->middleware(['can:result.publish', 'throttle:30,1'])->name('results.publish');
        Route::post('items/{item}/rekey', [ResultsController::class, 'rekey'])->whereNumber('item')->middleware(['can:result.rescore', 'throttle:30,1'])->name('results.items.rekey');
    });
});
