<?php

use App\Http\Controllers\QuestionBank\ApprovalController;
use App\Http\Controllers\QuestionBank\ImportController;
use App\Http\Controllers\QuestionBank\MediaController;
use App\Http\Controllers\QuestionBank\QuestionController;
use App\Http\Controllers\QuestionBank\ReviewController;
use App\Http\Controllers\QuestionBank\TagController;
use Illuminate\Support\Facades\Route;

/*
 * Question bank. Every route needs a catalogue permission; the actions behind them check the campus
 * being worked in and the user's exam access (courses, programmes) again.
 */
Route::middleware('auth')->group(function () {
    Route::get('questions', [QuestionController::class, 'index'])->middleware('can:qbank.question.view')->name('questions.index');

    // Exporting is its own permission: the file, answer keys and all, leaves the system.
    Route::get('questions/export', [QuestionController::class, 'export'])->middleware(['can:qbank.question.export', 'throttle:20,1'])->name('questions.export');

    Route::get('questions/create', [QuestionController::class, 'create'])->middleware('can:qbank.question.create')->name('questions.create');
    Route::post('questions', [QuestionController::class, 'store'])->middleware('can:qbank.question.create')->name('questions.store');

    // Importing from a spreadsheet: checking is one permission, putting them in the bank another.
    Route::prefix('questions/imports')->name('imports.')->group(function () {
        Route::get('/', [ImportController::class, 'index'])->middleware('can:qbank.import.run')->name('index');
        Route::get('template', [ImportController::class, 'template'])->middleware('can:qbank.import.run')->name('template');
        Route::post('/', [ImportController::class, 'store'])->middleware(['can:qbank.import.run', 'throttle:20,1'])->name('store');
        Route::get('{import}', [ImportController::class, 'show'])->middleware('can:qbank.import.run')->whereNumber('import')->name('show');
        Route::post('{import}/commit', [ImportController::class, 'commit'])->middleware(['can:qbank.import.commit', 'throttle:20,1'])->whereNumber('import')->name('commit');
        Route::delete('{import}', [ImportController::class, 'destroy'])->middleware('can:qbank.import.run')->whereNumber('import')->name('destroy');
    });

    // Review and approval. The workspace is opened by reviewers, approvers and the author, so it
    // only needs the right to see the question; each action checks its own permission.
    // Either level of reviewer may open these; the controller checks which.
    Route::get('reviews', [ReviewController::class, 'index'])->name('reviews.index');
    Route::get('approvals', [ApprovalController::class, 'index'])->middleware('can:qbank.question.approve')->name('approvals.index');

    Route::get('questions/curriculum', [QuestionController::class, 'curriculum'])->middleware('can:qbank.question.view')->name('questions.curriculum');
    Route::post('questions/check', [QuestionController::class, 'check'])->middleware(['can:qbank.question.view', 'throttle:120,1'])->name('questions.check');

    // Pictures are private: uploaded by authors, served only to staff of the same campus.
    Route::post('questions/media', [MediaController::class, 'store'])->middleware(['can:qbank.question.create', 'throttle:60,1'])->name('questions.media.store');
    Route::get('questions/media/{media}', [MediaController::class, 'show'])->middleware('can:qbank.question.view')->whereNumber('media')->name('questions.media.show');

    Route::post('questions/tags', [TagController::class, 'store'])->middleware(['can:qbank.question.create', 'throttle:60,1'])->name('questions.tags.store');

    Route::prefix('questions/{question}')->whereNumber('question')->group(function () {
        Route::get('/', [QuestionController::class, 'history'])->middleware('can:qbank.question.view')->name('questions.history');
        Route::get('diff', [QuestionController::class, 'diff'])->middleware('can:qbank.question.view')->name('questions.diff');
        Route::post('versions', [QuestionController::class, 'newVersion'])->name('questions.versions.store');

        Route::prefix('versions/{version}')->whereNumber('version')->group(function () {
            Route::get('/', [QuestionController::class, 'show'])->middleware('can:qbank.question.view')->name('questions.show');
            Route::get('edit', [QuestionController::class, 'edit'])->name('questions.edit');
            Route::put('/', [QuestionController::class, 'update'])->name('questions.update');
            Route::post('submit', [QuestionController::class, 'submit'])->middleware('can:qbank.question.submit')->name('questions.submit');

            Route::get('review', [ReviewController::class, 'show'])->middleware('can:qbank.question.view')->name('reviews.show');
            Route::post('review', [ReviewController::class, 'store'])->middleware('throttle:60,1')->name('reviews.store');
            Route::post('reviewers', [ReviewController::class, 'assign'])->middleware(['can:qbank.review.assign', 'throttle:60,1'])->name('reviews.assign');
            Route::delete('reviewers/{assignment}', [ReviewController::class, 'cancelAssignment'])->middleware('can:qbank.review.assign')->whereNumber('assignment')->name('reviews.cancel');

            Route::post('decide', [ApprovalController::class, 'decide'])->middleware(['can:qbank.question.approve', 'throttle:60,1'])->name('approvals.decide');
            Route::post('approve', [ApprovalController::class, 'approve'])->middleware(['can:qbank.question.approve', 'throttle:60,1'])->name('approvals.approve');
            Route::post('activate', [ApprovalController::class, 'activate'])->middleware(['can:qbank.question.approve', 'throttle:60,1'])->name('approvals.activate');
            Route::post('reject', [ApprovalController::class, 'reject'])->middleware(['can:qbank.question.approve', 'throttle:60,1'])->name('approvals.reject');
        });
    });
});
