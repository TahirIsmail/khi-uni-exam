<?php

use App\Http\Controllers\Setup\AcademicController;
use App\Http\Controllers\Setup\RoleController;
use App\Http\Controllers\Setup\SettingsController;
use App\Http\Controllers\Setup\StaffController;
use Illuminate\Support\Facades\Route;

/*
 * Setup: what kmu-cms keeps for KMU, kept here because this app runs on its own — staff and their
 * roles, the role checkboxes, programmes, courses and their topics, intakes, exam types and the
 * module settings. The Super Admin's alone.
 */
Route::middleware(['auth', 'can:setup.manage'])->prefix('setup')->name('setup.')->group(function () {
    Route::get('/', [SettingsController::class, 'index'])->name('index');
    Route::put('settings', [SettingsController::class, 'update'])->middleware('throttle:30,1')->name('settings.update');
    Route::post('campuses', [SettingsController::class, 'storeBranch'])->middleware('throttle:30,1')->name('campuses.store');
    Route::put('campuses/{branch}', [SettingsController::class, 'updateBranch'])->whereNumber('branch')->middleware('throttle:30,1')->name('campuses.update');

    Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
    Route::post('staff', [StaffController::class, 'store'])->middleware('throttle:30,1')->name('staff.store');
    Route::put('staff/{staff}', [StaffController::class, 'update'])->whereNumber('staff')->middleware('throttle:60,1')->name('staff.update');

    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::post('roles', [RoleController::class, 'store'])->middleware('throttle:30,1')->name('roles.store');
    Route::put('roles/{role}', [RoleController::class, 'update'])->whereNumber('role')->middleware('throttle:60,1')->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->whereNumber('role')->middleware('throttle:30,1')->name('roles.destroy');

    Route::get('programmes', [AcademicController::class, 'programmes'])->name('programmes.index');
    Route::post('programmes', [AcademicController::class, 'storeProgramme'])->middleware('throttle:30,1')->name('programmes.store');
    Route::put('programmes/{programme}', [AcademicController::class, 'updateProgramme'])->whereNumber('programme')->middleware('throttle:60,1')->name('programmes.update');
    Route::post('programmes/{programme}/courses', [AcademicController::class, 'storeCourse'])->whereNumber('programme')->middleware('throttle:60,1')->name('courses.store');
    Route::put('courses/{course}', [AcademicController::class, 'updateCourse'])->whereNumber('course')->middleware('throttle:60,1')->name('courses.update');
    Route::get('courses/{course}/curriculum', [AcademicController::class, 'curriculum'])->whereNumber('course')->name('curriculum.show');
    Route::post('courses/{course}/curriculum', [AcademicController::class, 'storeNode'])->whereNumber('course')->middleware('throttle:120,1')->name('curriculum.store');
    Route::put('curriculum/{node}', [AcademicController::class, 'updateNode'])->whereNumber('node')->middleware('throttle:120,1')->name('curriculum.update');

    Route::get('lists', [AcademicController::class, 'lists'])->name('lists.index');
    Route::post('intakes', [AcademicController::class, 'storeIntake'])->middleware('throttle:30,1')->name('intakes.store');
    Route::put('intakes/{intake}', [AcademicController::class, 'updateIntake'])->whereNumber('intake')->middleware('throttle:30,1')->name('intakes.update');
    Route::post('exam-types', [AcademicController::class, 'storeExamType'])->middleware('throttle:30,1')->name('exam-types.store');
    Route::put('exam-types/{type}', [AcademicController::class, 'updateExamType'])->whereNumber('type')->middleware('throttle:30,1')->name('exam-types.update');
    Route::post('disciplines', [AcademicController::class, 'storeDiscipline'])->middleware('throttle:30,1')->name('disciplines.store');
    Route::put('disciplines/{discipline}', [AcademicController::class, 'updateDiscipline'])->whereNumber('discipline')->middleware('throttle:30,1')->name('disciplines.update');
});
