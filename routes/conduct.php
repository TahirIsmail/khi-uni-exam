<?php

use App\Http\Controllers\Exam\CandidateController;
use App\Http\Controllers\Exam\CentreController;
use App\Http\Controllers\Exam\CheckInController;
use App\Http\Controllers\Exam\ConductController;
use App\Http\Controllers\Exam\MonitorController;
use App\Http\Controllers\Exam\PreviewController;
use App\Http\Controllers\Exam\ProctorController;
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

        // Centre device approval (step 19): a device not seen before at a centre needs one-time approval.
        Route::get('{centre}/devices', [CentreController::class, 'devices'])->whereNumber('centre')->middleware('can:centre.view')->name('conduct.centres.devices');
        Route::post('{centre}/devices/{device}/approve', [CentreController::class, 'approveDevice'])->whereNumber('centre')->whereNumber('device')->middleware(['can:centre.manage', 'throttle:30,1'])->name('conduct.centres.devices.approve');
    });

    Route::prefix('{exam}')->whereNumber('exam')->group(function () {
        Route::get('candidates', [CandidateController::class, 'index'])->middleware('can:candidate.view')->name('conduct.candidates');
        Route::post('candidates/import', [CandidateController::class, 'import'])->middleware(['can:candidate.manage', 'throttle:10,1'])->name('conduct.candidates.import');
        Route::post('candidates/allocate', [CandidateController::class, 'allocateAuto'])->middleware(['can:centre.allocate', 'throttle:20,1'])->name('conduct.candidates.allocate');
        Route::post('candidates/{candidate}/allocate', [CandidateController::class, 'allocateOne'])->whereNumber('candidate')->middleware(['can:centre.allocate', 'throttle:60,1'])->name('conduct.candidates.allocate-one');
        Route::post('candidates/{candidate}/extra-time', [CandidateController::class, 'extraTime'])->whereNumber('candidate')->middleware(['can:candidate.extra_time', 'throttle:60,1'])->name('conduct.candidates.extra-time');

        // The exam screen as a candidate sees it, nothing saved — to check the paper or show students.
        Route::get('preview', [PreviewController::class, 'show'])->middleware('can:exam.view')->name('conduct.preview');
        // Finishing the preview: how the answers just given would be marked, shown to staff, never stored.
        Route::post('preview/check', [PreviewController::class, 'check'])->middleware(['can:exam.view', 'throttle:30,1'])->name('conduct.preview.check');
        Route::get('preview/check', [PreviewController::class, 'again'])->middleware('can:exam.view');
        Route::get('checkin', [CheckInController::class, 'index'])->middleware('can:candidate.checkin')->name('conduct.checkin');
        Route::post('checkin/{candidate}', [CheckInController::class, 'checkIn'])->whereNumber('candidate')->middleware(['can:candidate.checkin', 'throttle:60,1'])->name('conduct.checkin.do');
        Route::post('checkin/{candidate}/reissue-pin', [CheckInController::class, 'reissuePin'])->whereNumber('candidate')->middleware(['can:candidate.checkin', 'throttle:60,1'])->name('conduct.checkin.reissue-pin');

        // Watching an examination while it is sat, and the invigilator's own actions on it (step 18).
        Route::get('monitor', [MonitorController::class, 'index'])->middleware('can:delivery.monitor')->name('conduct.monitor');
        Route::post('monitor/attempts/{attempt}/end-session', [MonitorController::class, 'endSession'])->whereNumber('attempt')->middleware(['can:delivery.session_control', 'throttle:30,1'])->name('conduct.monitor.end-session');
        Route::post('monitor/attempts/{attempt}/add-time', [MonitorController::class, 'addTime'])->whereNumber('attempt')->middleware(['can:delivery.session_control', 'throttle:30,1'])->name('conduct.monitor.add-time');
        Route::post('monitor/rooms/{room}/pause', [MonitorController::class, 'pauseRoom'])->whereNumber('room')->middleware(['can:delivery.session_control', 'throttle:30,1'])->name('conduct.monitor.pause-room');
        Route::post('monitor/rooms/{room}/resume', [MonitorController::class, 'resumeRoom'])->whereNumber('room')->middleware(['can:delivery.session_control', 'throttle:30,1'])->name('conduct.monitor.resume-room');

        // Reviewing proctoring cases and recording the committee's decision (step 19).
        Route::get('proctoring', [ProctorController::class, 'index'])->middleware('can:proctor.events.view')->name('conduct.proctoring');
        Route::get('proctoring/{attempt}', [ProctorController::class, 'show'])->whereNumber('attempt')->middleware('can:proctor.events.view')->name('conduct.proctoring.case');
        Route::post('proctoring/{attempt}/decide', [ProctorController::class, 'decide'])->whereNumber('attempt')->middleware(['can:proctor.review.decide', 'throttle:30,1'])->name('conduct.proctoring.decide');
    });
});
