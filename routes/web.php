<?php

use App\Http\Controllers\Identity\LogoutController;
use Illuminate\Support\Facades\Route;

// No public pages: staff arrive signed in from kmu-cms (routes/sso.php); guests are sent to the CMS login.
Route::redirect('/', '/dashboard')->name('home');

Route::middleware('auth')->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::post('logout', [LogoutController::class, 'logout'])->name('logout');
});

require __DIR__.'/mfa.php';
