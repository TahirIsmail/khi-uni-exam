<?php

use Illuminate\Support\Facades\Route;

// There is no public landing page: guests are sent to the login screen by the auth middleware.
Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
