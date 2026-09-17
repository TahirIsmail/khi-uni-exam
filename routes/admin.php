<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\RolePermissionController;
use App\Http\Controllers\Admin\StaffScopeController;
use Illuminate\Support\Facades\Route;

/*
 * Administration: who may do what, where, and what happened. Every route needs a catalogue
 * permission; the actions behind them also check branches and prevent self-escalation.
 */
Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::middleware('can:admin.roles.manage')->group(function () {
        Route::get('roles', [RolePermissionController::class, 'index'])->name('roles.index');
        Route::put('roles/{role}', [RolePermissionController::class, 'update'])->whereNumber('role')->name('roles.update');
    });

    Route::middleware('can:admin.users.manage')->group(function () {
        Route::get('staff', [StaffScopeController::class, 'index'])->name('staff.index');
        Route::get('staff/{staff}', [StaffScopeController::class, 'show'])->whereNumber('staff')->name('staff.show');
        Route::post('staff/{staff}/scopes', [StaffScopeController::class, 'store'])->whereNumber('staff')->name('staff.scopes.store');
        Route::delete('staff/{staff}/scopes/{scope}', [StaffScopeController::class, 'destroy'])->whereNumber(['staff', 'scope'])->name('staff.scopes.destroy');
    });

    Route::get('audit', [AuditLogController::class, 'index'])->middleware(['can:audit.view', 'throttle:60,1'])->name('audit.index');
    Route::get('audit/export', [AuditLogController::class, 'export'])->middleware(['can:audit.export', 'throttle:10,1'])->name('audit.export');
});
