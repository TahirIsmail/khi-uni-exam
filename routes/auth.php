<?php

use App\Http\Controllers\Identity\LoginController;
use Illuminate\Support\Facades\Route;

// Staff sign in here with email and password; accounts are set up under Setup → Staff.
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
});
