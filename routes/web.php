<?php

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Identity\BranchController;
use App\Http\Controllers\Identity\LogoutController;
use Illuminate\Support\Facades\Route;

// No public pages: staff arrive signed in from kmu-cms (routes/sso.php); guests are sent to the CMS login.
Route::redirect('/', '/dashboard')->name('home');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::put('branch', [BranchController::class, 'update'])->name('branch.update');

    Route::post('logout', [LogoutController::class, 'logout'])->name('logout');
});

require __DIR__.'/mfa.php';
require __DIR__.'/qbank.php';
require __DIR__.'/exams.php';
require __DIR__.'/conduct.php';
require __DIR__.'/marking.php';
require __DIR__.'/results.php';
require __DIR__.'/sit.php';
