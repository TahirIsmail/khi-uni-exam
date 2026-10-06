<?php

use App\Http\Controllers\Exam\ReportsController;
use Illuminate\Support\Facades\Route;

/*
 * Result sheets: the class tabulation sheet, a candidate's detailed marks certificate, and the CSV
 * of the sheet. Two rights, both of which already exist as CMS checkboxes under
 * "Question Bank & Exams": Exam Reports (report.view) and Export Exam Reports (report.export).
 */
Route::middleware('auth')->prefix('reports')->group(function () {
    Route::get('/', [ReportsController::class, 'index'])->middleware('can:report.view')->name('reports.index');
    Route::get('tabulation', [ReportsController::class, 'tabulation'])->middleware('can:report.view')->name('reports.tabulation');
    Route::get('tabulation.csv', [ReportsController::class, 'tabulationCsv'])->middleware(['can:report.export', 'throttle:20,1'])->name('reports.tabulation.csv');
    Route::get('candidates/{candidateNo}', [ReportsController::class, 'statement'])->middleware('can:report.view')->name('reports.statement');
});
