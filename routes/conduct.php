<?php

use App\Http\Controllers\Exam\CandidateController;
use App\Http\Controllers\Exam\CentreController;
use App\Http\Controllers\Exam\CheckInController;
use App\Http\Controllers\Exam\ConductController;
use Illuminate\Support\Facades\Route;

/*
 * "Conduct Exam" in the CMS's Exams menu (exam phase, step 17): candidates, centres, rooms,
 * allocation and check-in. Every route needs a catalogue permission; the actions behind them check
 * the campus being worked in again, and candidate actions the course too.
 */
Route::middleware('auth')->prefix('exams')->group(function () {
    Route::get('conduct', [ConductController::class, 'index'])->middleware('can:exam.conduct.access')->name('conduct.index');

    Route::prefix('conduct/centres')->group(function () {
        Route::get('/', [CentreController::class, 'index'])->middleware('can:centre.view')->name('conduct.centres');
        Route::post('/', [CentreController::class, 'store'])->middleware(['can:centre.manage', 'throttle:30,1'])->name('conduct.centres.store');
        Route::put('{centre}', [CentreController::class, 'update'])->whereNumber('centre')->middleware(['can:centre.manage', 'throttle:30,1'])->name('conduct.centres.update');
        Route::post('{centre}/rooms', [CentreController::class, 'storeRoom'])->whereNumber('centre')->middleware(['can:centre.manage', 'throttle:30,1'])->name('conduct.rooms.store');
        Route::put('{centre}/rooms/{room}', [CentreController::class, 'updateRoom'])->whereNumber('centre')->whereNumber('room')->middleware(['can:centre.manage', 'throttle:30,1'])->name('conduct.rooms.update');
    });

    Route::prefix('{exam}')->whereNumber('exam')->group(function () {
        Route::get('candidates', [CandidateController::class, 'index'])->middleware('can:candidate.view')->name('conduct.candidates');
        Route::post('candidates/import', [CandidateController::class, 'import'])->middleware(['can:candidate.manage', 'throttle:10,1'])->name('conduct.candidates.import');
        Route::post('candidates/allocate', [CandidateController::class, 'allocateAuto'])->middleware(['can:centre.allocate', 'throttle:20,1'])->name('conduct.candidates.allocate');
        Route::post('candidates/{candidate}/allocate', [CandidateController::class, 'allocateOne'])->whereNumber('candidate')->middleware(['can:centre.allocate', 'throttle:60,1'])->name('conduct.candidates.allocate-one');
        Route::post('candidates/{candidate}/extra-time', [CandidateController::class, 'extraTime'])->whereNumber('candidate')->middleware(['can:candidate.extra_time', 'throttle:60,1'])->name('conduct.candidates.extra-time');

        Route::get('checkin', [CheckInController::class, 'index'])->middleware('can:candidate.checkin')->name('conduct.checkin');
        Route::post('checkin/{candidate}', [CheckInController::class, 'checkIn'])->whereNumber('candidate')->middleware(['can:candidate.checkin', 'throttle:60,1'])->name('conduct.checkin.do');
        Route::post('checkin/{candidate}/reissue-pin', [CheckInController::class, 'reissuePin'])->whereNumber('candidate')->middleware(['can:candidate.checkin', 'throttle:60,1'])->name('conduct.checkin.reissue-pin');
    });
});
