<?php

use App\Http\Controllers\Console\AuditController;
use App\Http\Controllers\Console\AuthController;
use App\Http\Controllers\Console\CollationController;
use App\Http\Controllers\Console\CompareController;
use App\Http\Controllers\Console\DashboardController;
use App\Http\Controllers\Console\IncidentController;
use App\Http\Controllers\Console\MonitorController;
use App\Http\Controllers\Console\OfficialCollationController;
use App\Http\Controllers\Console\OfficialImportController;
use App\Http\Controllers\Console\OfficialResultController;
use App\Http\Controllers\Console\SystemController;
use App\Http\Controllers\Console\UserController;
use App\Http\Middleware\RequireAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.attempt');
Route::post('/setup', [AuthController::class, 'setup'])->middleware('throttle:5,1')->name('setup');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::view('/offline', 'offline')->name('offline');

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/spread', [DashboardController::class, 'spread'])->name('spread');

    Route::get('/collation', [CollationController::class, 'index'])->name('collation');
    Route::get('/collation/{lga}', [CollationController::class, 'lga'])->name('collation.lga');
    Route::get('/collation/{lga}/{ward}', [CollationController::class, 'ward'])->name('collation.ward');

    Route::get('/monitor', [MonitorController::class, 'index'])->name('monitor');
    Route::get('/monitor/{lga}', [MonitorController::class, 'lga'])->name('monitor.lga');
    Route::get('/monitor/{lga}/{ward}', [MonitorController::class, 'ward'])->name('monitor.ward');

    Route::get('/incidents', [IncidentController::class, 'index'])->name('incidents');
    Route::post('/incidents/{incident:reference}/acknowledge', [IncidentController::class, 'acknowledge'])->name('incidents.acknowledge');
    Route::post('/incidents/{incident:reference}/resolve', [IncidentController::class, 'resolve'])->name('incidents.resolve');
    Route::post('/incidents/{incident:reference}/reopen', [IncidentController::class, 'reopen'])->name('incidents.reopen');

    // Official results (IReV per PU, declared EC8B/EC8C) and the comparison.
    Route::get('/compare', [CompareController::class, 'index'])->name('compare');
    Route::get('/official', [OfficialResultController::class, 'index'])->name('official');
    Route::get('/official/pu/{code}', [OfficialResultController::class, 'edit'])->name('official.pu');
    Route::put('/official/pu/{code}', [OfficialResultController::class, 'update'])->name('official.pu.update');
    Route::get('/official/collations', [OfficialCollationController::class, 'index'])->name('official.collations');
    Route::get('/official/collations/{level}/{lga}/{ward?}', [OfficialCollationController::class, 'edit'])->name('official.collation');
    Route::put('/official/collations/{level}/{lga}/{ward?}', [OfficialCollationController::class, 'update'])->name('official.collation.update');

    Route::middleware(RequireAdmin::class)->group(function () {
        // The export includes agents' phone numbers.
        Route::get('/compare/export', [CompareController::class, 'export'])->name('compare.export');
        Route::delete('/official/pu/{code}', [OfficialResultController::class, 'destroy'])->name('official.pu.destroy');
        Route::get('/official/import', [OfficialImportController::class, 'show'])->name('official.import');
        Route::post('/official/import', [OfficialImportController::class, 'store'])->name('official.import.store');
    });

    // After /compare/export so "export" isn't taken for an LGA.
    Route::get('/compare/{lga}', [CompareController::class, 'lga'])->name('compare.lga');

    Route::middleware(RequireAdmin::class)->group(function () {
        Route::get('/system', [SystemController::class, 'show'])->name('system');
        Route::post('/system/sync', [SystemController::class, 'sync'])->name('system.sync');
        Route::post('/system/reprocess', [SystemController::class, 'reprocess'])->name('system.reprocess');
        Route::post('/system/migrate', [SystemController::class, 'migrate'])->name('system.migrate');
        Route::post('/system/data-view', [SystemController::class, 'dataView'])->name('system.data-view');

        Route::get('/users', [UserController::class, 'index'])->name('users');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('/audit', [AuditController::class, 'index'])->name('audit');
    });
});
