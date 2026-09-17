<?php

use App\Http\Controllers\Identity\CmsSsoController;
use Illuminate\Support\Facades\Route;

// Staff arriving from kmu-cms with a signed ticket (ADR-0002). POST only; CSRF-exempt; rate limited.
Route::post('sso/cms', CmsSsoController::class)
    ->middleware('throttle:cms-sso')
    ->name('sso.cms');
