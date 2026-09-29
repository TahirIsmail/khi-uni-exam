<?php

use App\Http\Controllers\Exam\MarkingController;
use Illuminate\Support\Facades\Route;

/*
 * Marking (exam phase, step 20): assigning examiners, marking manually-marked items against the
 * question's rubric, and adjudicating disagreements between two examiners. Auto-marking of
 * objective items needs no route — it happens on submission (App\Domain\Marking\Actions\AutoMarkAttempt).
 */
/*
 * The three marking permissions are an "any of these" rule, which the `can:` middleware cannot
 * express, so MarkingController checks them itself — on the list as well as on one examination.
 */
Route::middleware('auth')->prefix('marking')->group(function () {
    Route::get('/', [MarkingController::class, 'index'])->name('marking.index');

    Route::prefix('{exam}')->whereNumber('exam')->group(function () {
        Route::get('/', [MarkingController::class, 'show'])->name('marking.show');
        Route::post('examiners', [MarkingController::class, 'assignExaminer'])->middleware(['can:marking.assign', 'throttle:30,1'])->name('marking.examiners.assign');

        // One candidate's whole paper: the way in to an item the queue does not list, such as a
        // machine-marked one an examiner has been asked to look at again.
        Route::get('attempts/{attempt}', [MarkingController::class, 'attempt'])->whereNumber('attempt')
            ->middleware('can:marking.mark')->name('marking.attempts.show');

        Route::get('items/{item}', [MarkingController::class, 'showItem'])->whereNumber('item')->name('marking.items.show');
        Route::post('items/{item}/mark', [MarkingController::class, 'mark'])->whereNumber('item')->middleware(['can:marking.mark', 'throttle:60,1'])->name('marking.items.mark');
        Route::post('items/{item}/adjudicate', [MarkingController::class, 'adjudicate'])->whereNumber('item')->middleware(['can:marking.adjudicate', 'throttle:30,1'])->name('marking.items.adjudicate');
    });
});
