<?php

use App\Http\Controllers\Exam\BlueprintController;
use App\Http\Controllers\Exam\ExaminationController;
use Illuminate\Support\Facades\Route;

/*
 * Examinations and their blueprints: "Create Exam" in the CMS's Exams menu opens /exams. Every route
 * needs a catalogue permission; the actions behind them check the campus being worked in and the
 * user's exam access (programmes, years, courses) again.
 */
Route::middleware('auth')->prefix('exams')->group(function () {
    Route::get('/', [ExaminationController::class, 'index'])->middleware('can:exam.access')->name('exams.index');
    Route::get('create', [ExaminationController::class, 'create'])->middleware('can:exam.create')->name('exams.create');
    Route::post('/', [ExaminationController::class, 'store'])->middleware(['can:exam.create', 'throttle:30,1'])->name('exams.store');

    Route::prefix('{exam}')->whereNumber('exam')->group(function () {
        Route::get('/', [ExaminationController::class, 'show'])->middleware('can:exam.access')->name('exams.show');
        Route::get('edit', [ExaminationController::class, 'edit'])->middleware('can:exam.create')->name('exams.edit');
        Route::put('/', [ExaminationController::class, 'update'])->middleware(['can:exam.create', 'throttle:30,1'])->name('exams.update');

        Route::get('blueprint', [BlueprintController::class, 'edit'])->middleware('can:exam.access')->name('blueprints.edit');
        Route::put('blueprint', [BlueprintController::class, 'update'])->middleware(['can:exam.blueprint.manage', 'throttle:60,1'])->name('blueprints.update');
        Route::post('blueprint/submit', [BlueprintController::class, 'submit'])->middleware(['can:exam.blueprint.manage', 'throttle:30,1'])->name('blueprints.submit');
        Route::post('blueprint/approve', [BlueprintController::class, 'approve'])->middleware(['can:exam.blueprint.approve', 'throttle:30,1'])->name('blueprints.approve');
        Route::post('blueprint/send-back', [BlueprintController::class, 'sendBack'])->middleware(['can:exam.blueprint.approve', 'throttle:30,1'])->name('blueprints.send-back');
        Route::post('blueprint/reopen', [BlueprintController::class, 'reopen'])->middleware(['can:exam.blueprint.approve', 'throttle:30,1'])->name('blueprints.reopen');
    });
});
