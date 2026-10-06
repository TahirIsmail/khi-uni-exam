<?php

use App\Http\Controllers\Exam\BlueprintController;
use App\Http\Controllers\Exam\ExaminationController;
use App\Http\Controllers\Exam\PaperController;
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

        // The paper: chosen from the question bank to match the approved blueprint.
        Route::prefix('paper')->group(function () {
            Route::get('/', [PaperController::class, 'show'])->middleware('can:exam.view')->name('papers.show');
            // The whole paper, question by question with its marks, to read or print.
            Route::get('preview', [PaperController::class, 'preview'])->middleware('can:exam.view')->name('papers.preview');
            Route::post('/', [PaperController::class, 'store'])->middleware(['can:exam.select_questions', 'throttle:30,1'])->name('papers.store');
            Route::put('/', [PaperController::class, 'update'])->middleware(['can:exam.select_questions', 'throttle:60,1'])->name('papers.update');
            Route::post('fill', [PaperController::class, 'fill'])->middleware(['can:exam.select_questions', 'throttle:20,1'])->name('papers.fill');
            Route::get('candidates', [PaperController::class, 'candidates'])->middleware(['can:exam.select_questions', 'throttle:120,1'])->name('papers.candidates');
            Route::post('items', [PaperController::class, 'addItem'])->middleware(['can:exam.select_questions', 'throttle:120,1'])->name('papers.items.add');
            Route::prefix('items/{item}')->whereNumber('item')->group(function () {
                Route::post('swap', [PaperController::class, 'swapItem'])->middleware(['can:exam.select_questions', 'throttle:120,1'])->name('papers.items.swap');
                Route::post('lock', [PaperController::class, 'lockItem'])->middleware(['can:exam.select_questions', 'throttle:120,1'])->name('papers.items.lock');
                Route::delete('/', [PaperController::class, 'removeItem'])->middleware(['can:exam.select_questions', 'throttle:120,1'])->name('papers.items.remove');
            });

            // Moderating and locking it (step 4): submitted by whoever built it, approved — or sent
            // back — by somebody else, finalised, then published; each its own right.
            Route::post('submit', [PaperController::class, 'submit'])->middleware(['can:exam.submit', 'throttle:30,1'])->name('papers.submit');
            Route::post('approve', [PaperController::class, 'approve'])->middleware(['can:exam.approve', 'throttle:30,1'])->name('papers.approve');
            Route::post('send-back', [PaperController::class, 'sendBack'])->middleware(['can:exam.approve', 'throttle:30,1'])->name('papers.send-back');
            Route::post('finalise', [PaperController::class, 'finalise'])->middleware(['can:exam.finalise', 'throttle:30,1'])->name('papers.finalise');
            Route::post('publish', [PaperController::class, 'publish'])->middleware(['can:exam.publish', 'throttle:30,1'])->name('papers.publish');
            Route::post('new-version', [PaperController::class, 'newVersion'])->middleware(['can:exam.unlock_version', 'throttle:30,1'])->name('papers.new-version');

            Route::post('comments', [PaperController::class, 'addComment'])->middleware(['can:exam.view', 'throttle:120,1'])->name('papers.comments.add');
            Route::post('comments/{comment}/resolve', [PaperController::class, 'resolveComment'])->middleware(['can:exam.approve', 'throttle:120,1'])->whereNumber('comment')->name('papers.comments.resolve');
        });
    });
});
