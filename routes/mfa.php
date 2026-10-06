<?php

use App\Http\Controllers\Identity\MfaController;
use Illuminate\Support\Facades\Route;

// Multi-factor authentication. Reachable before the MFA check (see RequireMfa).
Route::middleware('auth')->prefix('mfa')->name('mfa.')->group(function () {
    Route::get('challenge', [MfaController::class, 'challenge'])->name('challenge');
    Route::post('challenge', [MfaController::class, 'verify'])->middleware('throttle:mfa')->name('challenge.store');

    Route::get('setup', [MfaController::class, 'setup'])->name('setup');
    Route::post('setup', [MfaController::class, 'start'])->middleware('throttle:mfa')->name('setup.start');
    Route::post('setup/confirm', [MfaController::class, 'confirm'])->middleware('throttle:mfa')->name('setup.confirm');

    Route::get('recovery-codes', [MfaController::class, 'recoveryCodes'])->name('recovery-codes');
});
