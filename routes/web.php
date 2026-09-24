<?php

use App\Http\Controllers\AgentUploadController;
use App\Http\Controllers\Console\AuditController;
use App\Http\Controllers\Console\AuthController;
use App\Http\Controllers\Console\BroadcastController;
use App\Http\Controllers\Console\CollationController;
use App\Http\Controllers\Console\CompareController;
use App\Http\Controllers\Console\ContactController;
use App\Http\Controllers\Console\DashboardController;
use App\Http\Controllers\Console\IncidentController;
use App\Http\Controllers\Console\MonitorController;
use App\Http\Controllers\Console\OfficialCollationController;
use App\Http\Controllers\Console\OfficialImportController;
use App\Http\Controllers\Console\OfficialResultController;
use App\Http\Controllers\Console\PhotoController;
use App\Http\Controllers\Console\PushController;
use App\Http\Controllers\Console\SystemController;
use App\Http\Controllers\Console\TownHallManageController;
use App\Http\Controllers\Console\UserController;
use App\Http\Controllers\JoinController;
use App\Http\Controllers\TownHallController;
use App\Http\Middleware\RequireAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.attempt');
Route::post('/setup', [AuthController::class, 'setup'])->middleware('throttle:5,1')->name('setup');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::view('/offline', 'offline')->name('offline');

// Public sign-up for election updates (the broadcast opt-in).
Route::get('/join', [JoinController::class, 'show'])->name('join');
Route::post('/join', [JoinController::class, 'store'])->middleware('throttle:5,1')->name('join.store');

// The public digital town hall.
Route::get('/townhall', [TownHallController::class, 'index'])->name('townhall');
Route::get('/townhall/{session}', [TownHallController::class, 'show'])->name('townhall.show');
Route::get('/townhall/{session}/questions', [TownHallController::class, 'questions'])->middleware('throttle:60,1')->name('townhall.questions');
Route::post('/townhall/{session}/ask', [TownHallController::class, 'ask'])->middleware('throttle:6,1')->name('townhall.ask');

// Agents' no-login EC8A upload link (the token is the authorisation, see UploadLink).
Route::get('/u/{reference}/{token}', [AgentUploadController::class, 'show'])->middleware('throttle:30,1')->name('upload.agent');
Route::post('/u/{reference}/{token}', [AgentUploadController::class, 'store'])->middleware('throttle:10,1')->name('upload.agent.store');

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

    // Running the town hall (sessions themselves are admin-only, below).
    Route::get('/manage/townhall', [TownHallManageController::class, 'index'])->name('townhall.manage');
    Route::get('/manage/townhall/{session}', [TownHallManageController::class, 'moderate'])->name('townhall.moderate');
    Route::post('/manage/townhall/{session}/questions/{question}', [TownHallManageController::class, 'act'])->name('townhall.act');
    Route::get('/manage/townhall/{session}/present', [TownHallManageController::class, 'present'])->name('townhall.present');
    Route::get('/manage/townhall/{session}/present.json', [TownHallManageController::class, 'presentData'])->name('townhall.present.data');

    Route::get('/notifications', [PushController::class, 'show'])->name('push');
    Route::post('/push/subscribe', [PushController::class, 'subscribe'])->name('push.subscribe');
    Route::post('/push/unsubscribe', [PushController::class, 'unsubscribe'])->name('push.unsubscribe');
    Route::post('/push/test', [PushController::class, 'test'])->middleware('throttle:5,1')->name('push.test');

    Route::get('/photos', [PhotoController::class, 'index'])->name('photos');
    Route::post('/photos', [PhotoController::class, 'store'])->middleware('throttle:30,1')->name('photos.store');
    Route::get('/photos/{photo}', [PhotoController::class, 'show'])->name('photos.show');
    Route::get('/photos/{photo}/image/{size?}', [PhotoController::class, 'image'])->whereIn('size', ['thumb'])->name('photos.image');
    Route::post('/photos/{photo}/review', [PhotoController::class, 'review'])->name('photos.review');

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
        Route::delete('/photos/{photo}', [PhotoController::class, 'destroy'])->name('photos.destroy');
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
        Route::post('/system/push-keys', [SystemController::class, 'pushKeys'])->name('system.push-keys');
        Route::post('/system/data-view', [SystemController::class, 'dataView'])->name('system.data-view');

        Route::get('/users', [UserController::class, 'index'])->name('users');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('/audit', [AuditController::class, 'index'])->name('audit');

        Route::get('/manage/townhall-sessions/create', [TownHallManageController::class, 'create'])->name('townhall.create');
        Route::post('/manage/townhall-sessions', [TownHallManageController::class, 'store'])->name('townhall.store');
        Route::get('/manage/townhall-sessions/{session}/edit', [TownHallManageController::class, 'edit'])->name('townhall.edit');
        Route::put('/manage/townhall-sessions/{session}', [TownHallManageController::class, 'update'])->name('townhall.update');
        Route::post('/manage/townhall-sessions/{session}/reminder', [TownHallManageController::class, 'reminder'])->name('townhall.reminder');

        Route::get('/broadcasts', [BroadcastController::class, 'index'])->name('broadcasts');
        Route::get('/broadcasts/create', [BroadcastController::class, 'create'])->name('broadcasts.create');
        Route::post('/broadcasts', [BroadcastController::class, 'store'])->name('broadcasts.store');
        Route::get('/broadcasts/{broadcast}', [BroadcastController::class, 'show'])->name('broadcasts.show');
        Route::get('/broadcasts/{broadcast}/edit', [BroadcastController::class, 'edit'])->name('broadcasts.edit');
        Route::put('/broadcasts/{broadcast}', [BroadcastController::class, 'update'])->name('broadcasts.update');
        Route::post('/broadcasts/{broadcast}/send', [BroadcastController::class, 'send'])->name('broadcasts.send');
        Route::post('/broadcasts/{broadcast}/schedule', [BroadcastController::class, 'schedule'])->name('broadcasts.schedule');
        Route::post('/broadcasts/{broadcast}/cancel', [BroadcastController::class, 'cancel'])->name('broadcasts.cancel');
        Route::post('/broadcasts/{broadcast}/test', [BroadcastController::class, 'test'])->middleware('throttle:10,1')->name('broadcasts.test');

        Route::get('/contacts', [ContactController::class, 'index'])->name('contacts');
        Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
        Route::post('/contacts/import', [ContactController::class, 'import'])->name('contacts.import');
        Route::post('/contacts/opt-out', [ContactController::class, 'optOut'])->name('contacts.opt-out');
        Route::delete('/contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
    });
});
