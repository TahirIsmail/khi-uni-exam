<?php

use App\Http\Controllers\Identity\CmsSsoController;
use App\Http\Controllers\Identity\LogoutController;
use Illuminate\Support\Facades\Route;

// Staff arriving from kmu-cms with a signed ticket (ADR-0002). POST only; CSRF-exempt; rate limited.
Route::post('sso/cms', CmsSsoController::class)
    ->middleware('throttle:cms-sso')
    ->name('sso.cms');

// Single logout started in kmu-cms: a signed 60-second token ends this session too.
Route::get('sso/logout', [LogoutController::class, 'fromCms'])
    ->middleware('throttle:cms-sso')
    ->name('sso.logout');
