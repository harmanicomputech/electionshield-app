<?php

use App\Http\Controllers\Console\AuditController;
use App\Http\Controllers\Console\AuthController;
use App\Http\Controllers\Console\CollationController;
use App\Http\Controllers\Console\DashboardController;
use App\Http\Controllers\Console\IncidentController;
use App\Http\Controllers\Console\MonitorController;
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
