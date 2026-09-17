<?php

use App\Http\Controllers\QuestionBank\MediaController;
use App\Http\Controllers\QuestionBank\QuestionController;
use App\Http\Controllers\QuestionBank\TagController;
use Illuminate\Support\Facades\Route;

/*
 * Question bank. Every route needs a catalogue permission; the actions behind them check the campus
 * being worked in and the user's exam access (courses, programmes) again.
 */
Route::middleware('auth')->group(function () {
    Route::get('questions', [QuestionController::class, 'index'])->middleware('can:qbank.question.view')->name('questions.index');

    Route::get('questions/create', [QuestionController::class, 'create'])->middleware('can:qbank.question.create')->name('questions.create');
    Route::post('questions', [QuestionController::class, 'store'])->middleware('can:qbank.question.create')->name('questions.store');

    Route::get('questions/curriculum', [QuestionController::class, 'curriculum'])->middleware('can:qbank.question.view')->name('questions.curriculum');
    Route::post('questions/check', [QuestionController::class, 'check'])->middleware(['can:qbank.question.view', 'throttle:120,1'])->name('questions.check');

    // Pictures are private: uploaded by authors, served only to staff of the same campus.
    Route::post('questions/media', [MediaController::class, 'store'])->middleware(['can:qbank.question.create', 'throttle:60,1'])->name('questions.media.store');
    Route::get('questions/media/{media}', [MediaController::class, 'show'])->middleware('can:qbank.question.view')->whereNumber('media')->name('questions.media.show');

    Route::post('questions/tags', [TagController::class, 'store'])->middleware(['can:qbank.question.create', 'throttle:60,1'])->name('questions.tags.store');

    Route::prefix('questions/{question}')->whereNumber('question')->group(function () {
        Route::post('versions', [QuestionController::class, 'newVersion'])->name('questions.versions.store');

        Route::prefix('versions/{version}')->whereNumber('version')->group(function () {
            Route::get('/', [QuestionController::class, 'show'])->middleware('can:qbank.question.view')->name('questions.show');
            Route::get('edit', [QuestionController::class, 'edit'])->name('questions.edit');
            Route::put('/', [QuestionController::class, 'update'])->name('questions.update');
            Route::post('submit', [QuestionController::class, 'submit'])->middleware('can:qbank.question.submit')->name('questions.submit');
        });
    });
});
