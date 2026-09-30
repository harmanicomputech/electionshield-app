<?php

use App\Http\Controllers\AgentUploadController;
use App\Http\Controllers\Console\AccountController;
use App\Http\Controllers\Console\AgentAccountController;
use App\Http\Controllers\Console\AgentController;
use App\Http\Controllers\Console\AlertController;
use App\Http\Controllers\Console\AuditController;
use App\Http\Controllers\Console\AuthController;
use App\Http\Controllers\Console\BroadcastController;
use App\Http\Controllers\Console\CollationController;
use App\Http\Controllers\Console\CompareController;
use App\Http\Controllers\Console\ContactController;
use App\Http\Controllers\Console\CorrectionController;
use App\Http\Controllers\Console\DashboardController;
use App\Http\Controllers\Console\IncidentController;
use App\Http\Controllers\Console\IrevController;
use App\Http\Controllers\Console\LocationController;
use App\Http\Controllers\Console\MediaController;
use App\Http\Controllers\Console\MonitorController;
use App\Http\Controllers\Console\OfficialCollationController;
use App\Http\Controllers\Console\OfficialImportController;
use App\Http\Controllers\Console\OfficialResultController;
use App\Http\Controllers\Console\PhotoController;
use App\Http\Controllers\Console\PushController;
use App\Http\Controllers\Console\ReportController;
use App\Http\Controllers\Console\RoleController;
use App\Http\Controllers\Console\SystemController;
use App\Http\Controllers\Console\TownHallManageController;
use App\Http\Controllers\Console\UserController;
use App\Http\Controllers\Console\VolunteerController;
use App\Http\Controllers\Console\VoterIntelligenceController;
use App\Http\Controllers\Field\FieldController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\JoinController;
use App\Http\Controllers\RunnerController;
use App\Http\Controllers\TownHallController;
use App\Support\Permission;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.attempt');
Route::post('/login/agent', [AuthController::class, 'agentLogin'])->middleware('throttle:10,1')->name('login.agent');
Route::post('/setup', [AuthController::class, 'setup'])->middleware('throttle:5,1')->name('setup');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::view('/offline', 'offline')->name('offline');

// For an external pinger on hosts without per-minute cron (URL on the System page).
Route::get('/cron/{token}', RunnerController::class)->middleware('throttle:30,1')->name('runner');

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

// "Get the app" (no login needed, so agents can install before signing in). /app is the short link to share.
Route::get('/install', [InstallController::class, 'show'])->name('install');
Route::redirect('/app', '/install')->name('install.short');

Route::middleware('auth')->group(function () {
    Route::get('/account', [AccountController::class, 'show'])->name('account');
    Route::put('/account', [AccountController::class, 'update'])->name('account.update');
    Route::put('/account/password', [AccountController::class, 'password'])->middleware('throttle:10,1')->name('account.password');

    // Photo and video files: staff, or the agent who sent them (checked in the controller).
    Route::get('/media/{attachment}/file', [MediaController::class, 'file'])->name('media.file');

    // The phone's location (roles with "Location is recorded"; the controller checks).
    Route::post('/location', [LocationController::class, 'ping'])->middleware('throttle:30,1')->name('location.ping');

    Route::middleware('can:'.Permission::VIEW_LOCATIONS)->group(function () {
        Route::get('/locations', [LocationController::class, 'index'])->name('locations');
        Route::get('/locations/people', [LocationController::class, 'people'])->name('locations.people');
        Route::get('/locations/people/{user}', [LocationController::class, 'person'])->name('locations.person');
        Route::get('/locations/people/{user}/history.csv', [LocationController::class, 'personCsv'])->name('locations.person.csv');
        Route::post('/locations/people/{user}/tracking', [LocationController::class, 'tracking'])->middleware('can:'.Permission::MANAGE_USERS)->name('locations.person.tracking');
        Route::post('/locations/checkins/{presence}/pu', [LocationController::class, 'setPollingUnitLocation'])->name('locations.set-pu');
        Route::post('/locations/checkins/{presence}/review', [LocationController::class, 'review'])->name('locations.review');
    });

    // "How can you help?" sign-ups from USSD.
    Route::middleware('can:'.Permission::VIEW_VOLUNTEERS)->group(function () {
        Route::get('/volunteers', [VolunteerController::class, 'index'])->name('volunteers');
        Route::get('/volunteers/export', [VolunteerController::class, 'export'])->middleware('can:'.Permission::EXPORT_DATA)->name('volunteers.export');
        Route::post('/volunteers/{volunteer}/contacted', [VolunteerController::class, 'contacted'])->name('volunteers.contacted');
    });

    // Voter intelligence: voters by area and who they are (sourced figures only).
    Route::middleware('can:'.Permission::VIEW_VOTER_INTELLIGENCE)->prefix('intelligence')->group(function () {
        Route::middleware('can:'.Permission::MANAGE_VOTER_DATA)->group(function () {
            Route::get('/data/template.csv', [VoterIntelligenceController::class, 'template'])->name('intelligence.template');
            Route::post('/data/figures', [VoterIntelligenceController::class, 'importFigures'])->name('intelligence.figures');
            Route::post('/data/voters', [VoterIntelligenceController::class, 'importVoters'])->name('intelligence.voters');
            Route::post('/data/remove', [VoterIntelligenceController::class, 'removeSource'])->name('intelligence.remove');
        });
        Route::get('/data/register.csv', [VoterIntelligenceController::class, 'register'])->middleware('can:'.Permission::EXPORT_DATA)->name('intelligence.register');
        Route::get('/', [VoterIntelligenceController::class, 'state'])->name('intelligence');
        Route::get('/pu/{code}', [VoterIntelligenceController::class, 'pu'])->name('intelligence.pu');
        Route::get('/{lga}', [VoterIntelligenceController::class, 'lga'])->name('intelligence.lga');
        Route::get('/{lga}/{ward}', [VoterIntelligenceController::class, 'ward'])->where('ward', '.+')->name('intelligence.ward');
    });

    Route::get('/notifications', [PushController::class, 'show'])->name('push');
    Route::post('/push/subscribe', [PushController::class, 'subscribe'])->name('push.subscribe');
    Route::post('/push/unsubscribe', [PushController::class, 'unsubscribe'])->name('push.unsubscribe');
    Route::post('/push/test', [PushController::class, 'test'])->middleware('throttle:5,1')->name('push.test');

    // The agent pages (agents sign in with phone number and USSD PIN).
    Route::middleware('can:'.Permission::SUBMIT_FIELD_REPORTS)->prefix('field')->group(function () {
        Route::get('/', [FieldController::class, 'home'])->name('field');
        Route::post('/presence', [FieldController::class, 'presence'])->middleware('throttle:20,1')->name('field.presence');
        Route::post('/materials', [FieldController::class, 'materials'])->middleware('throttle:20,1')->name('field.materials');
        Route::get('/result', [FieldController::class, 'resultForm'])->name('field.result');
        Route::post('/result', [FieldController::class, 'submitResult'])->middleware('throttle:20,1')->name('field.result.store');
        Route::get('/incident', [FieldController::class, 'incidentForm'])->name('field.incident');
        Route::post('/incident', [FieldController::class, 'submitIncident'])->middleware('throttle:30,1')->name('field.incident.store');
        Route::get('/history', [FieldController::class, 'history'])->name('field.history');
        Route::post('/media/{reference}', [FieldController::class, 'addMedia'])->middleware('throttle:30,1')->name('field.media');
    });

    // Sends people without the dashboards (agents) to their own home page.
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::middleware('can:'.Permission::VIEW_DASHBOARDS)->group(function () {
        Route::get('/spread', [DashboardController::class, 'spread'])->name('spread');

        Route::get('/collation', [CollationController::class, 'index'])->name('collation');
        Route::get('/collation/{lga}', [CollationController::class, 'lga'])->name('collation.lga');
        Route::get('/collation/{lga}/{ward}', [CollationController::class, 'ward'])->where('ward', '.+')->name('collation.ward');

        Route::get('/monitor', [MonitorController::class, 'index'])->name('monitor');
        Route::get('/monitor/{lga}', [MonitorController::class, 'lga'])->name('monitor.lga');
        Route::get('/monitor/{lga}/{ward}', [MonitorController::class, 'ward'])->where('ward', '.+')->name('monitor.ward');

        Route::get('/evidence/{code}', [ReportController::class, 'evidence'])->name('evidence');
        Route::get('/sitrep', [ReportController::class, 'sitrep'])->name('sitrep');
        Route::get('/corrections', [CorrectionController::class, 'index'])->name('corrections');

        Route::get('/photos', [PhotoController::class, 'index'])->name('photos');
        Route::get('/photos/{photo}', [PhotoController::class, 'show'])->name('photos.show');
        Route::get('/photos/{photo}/image/{size?}', [PhotoController::class, 'image'])->whereIn('size', ['thumb'])->name('photos.image');
        Route::get('/media', [MediaController::class, 'index'])->name('media');

        // Official results (IReV per PU, declared EC8B/EC8C) and the comparison.
        Route::get('/compare', [CompareController::class, 'index'])->name('compare');
        Route::get('/official', [OfficialResultController::class, 'index'])->name('official');
        Route::get('/official/pu/{code}', [OfficialResultController::class, 'edit'])->name('official.pu');
        Route::get('/official/pu/{code}/sheet', [OfficialResultController::class, 'sheet'])->name('official.pu.sheet');
        Route::get('/official/collations', [OfficialCollationController::class, 'index'])->name('official.collations');
        Route::get('/official/collations/{level}/{lga}/{ward?}', [OfficialCollationController::class, 'edit'])->where('ward', '.+')->name('official.collation');
    });

    Route::middleware('can:'.Permission::REVIEW_CORRECTIONS)->group(function () {
        Route::post('/corrections/{reference}/approve', [CorrectionController::class, 'approve'])->name('corrections.approve');
        Route::post('/corrections/{reference}/reject', [CorrectionController::class, 'reject'])->name('corrections.reject');
    });

    // Pop-ups for new results and incidents (what each person gets follows their permissions).
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts');
    Route::post('/alerts/snooze', [AlertController::class, 'snooze'])->name('alerts.snooze');
    Route::post('/results/{reference}/acknowledge', [AlertController::class, 'acknowledgeResult'])->middleware('can:'.Permission::ACKNOWLEDGE_RESULTS)->name('results.acknowledge');

    Route::get('/incidents', [IncidentController::class, 'index'])->middleware('can:'.Permission::VIEW_INCIDENTS)->name('incidents');
    Route::middleware('can:'.Permission::RESPOND_INCIDENTS)->group(function () {
        Route::post('/incidents/{incident:reference}/acknowledge', [IncidentController::class, 'acknowledge'])->name('incidents.acknowledge');
        Route::post('/incidents/{incident:reference}/resolve', [IncidentController::class, 'resolve'])->name('incidents.resolve');
        Route::post('/incidents/{incident:reference}/reopen', [IncidentController::class, 'reopen'])->name('incidents.reopen');
    });

    Route::get('/agents', [AgentController::class, 'index'])->middleware('can:'.Permission::VIEW_AGENTS)->name('agents');
    Route::middleware('can:'.Permission::MANAGE_AGENTS)->group(function () {
        Route::post('/agents', [AgentAccountController::class, 'store'])->middleware('throttle:30,1')->name('agents.store');
        Route::post('/agents/{agent}/reset-pin', [AgentAccountController::class, 'resetPin'])->middleware('throttle:30,1')->name('agents.reset-pin');
    });

    Route::middleware('can:'.Permission::REVIEW_MEDIA)->group(function () {
        Route::post('/photos', [PhotoController::class, 'store'])->middleware('throttle:30,1')->name('photos.store');
        Route::post('/photos/{photo}/review', [PhotoController::class, 'review'])->name('photos.review');
        Route::post('/media/{attachment}/review', [MediaController::class, 'review'])->name('media.review');
    });

    Route::middleware('can:'.Permission::DELETE_EVIDENCE)->group(function () {
        Route::delete('/photos/{photo}', [PhotoController::class, 'destroy'])->name('photos.destroy');
        Route::delete('/media/{attachment}', [MediaController::class, 'destroy'])->name('media.destroy');
        Route::delete('/official/pu/{code}', [OfficialResultController::class, 'destroy'])->name('official.pu.destroy');
    });

    Route::middleware('can:'.Permission::MANAGE_OFFICIAL_RESULTS)->group(function () {
        Route::put('/official/pu/{code}', [OfficialResultController::class, 'update'])->name('official.pu.update');
        Route::post('/official/pu/{code}/read', [OfficialResultController::class, 'read'])->middleware('throttle:20,1')->name('official.pu.read');
        Route::put('/official/collations/{level}/{lga}/{ward?}', [OfficialCollationController::class, 'update'])->where('ward', '.+')->name('official.collation.update');
        Route::get('/official/irev', [IrevController::class, 'index'])->name('official.irev');
        Route::post('/official/irev/elections', [IrevController::class, 'elections'])->middleware('throttle:10,1')->name('official.irev.elections');
        Route::post('/official/irev/follow', [IrevController::class, 'follow'])->name('official.irev.follow');
        Route::post('/official/irev/automatic', [IrevController::class, 'automatic'])->name('official.irev.automatic');
        Route::post('/official/irev/check', [IrevController::class, 'check'])->middleware('throttle:6,1')->name('official.irev.check');
        Route::post('/official/irev/{document}/match', [IrevController::class, 'match'])->name('official.irev.match');
        Route::get('/official/import', [OfficialImportController::class, 'show'])->name('official.import');
        Route::post('/official/import', [OfficialImportController::class, 'store'])->name('official.import.store');
    });

    Route::middleware('can:'.Permission::EXPORT_DATA)->group(function () {
        // The exports include agents' phone numbers.
        Route::get('/compare/export', [CompareController::class, 'export'])->name('compare.export');
        Route::get('/agents/export', [AgentController::class, 'export'])->name('agents.export');
        Route::get('/system/backup', [SystemController::class, 'backup'])->name('system.backup');
    });

    // After /compare/export so "export" isn't taken for an LGA.
    Route::get('/compare/{lga}', [CompareController::class, 'lga'])->middleware('can:'.Permission::VIEW_DASHBOARDS)->name('compare.lga');

    Route::middleware('can:'.Permission::MODERATE_TOWNHALL)->group(function () {
        Route::get('/manage/townhall', [TownHallManageController::class, 'index'])->name('townhall.manage');
        Route::get('/manage/townhall/{session}', [TownHallManageController::class, 'moderate'])->name('townhall.moderate');
        Route::post('/manage/townhall/{session}/questions/{question}', [TownHallManageController::class, 'act'])->name('townhall.act');
        Route::get('/manage/townhall/{session}/present', [TownHallManageController::class, 'present'])->name('townhall.present');
        Route::get('/manage/townhall/{session}/present.json', [TownHallManageController::class, 'presentData'])->name('townhall.present.data');
    });

    Route::middleware('can:'.Permission::MANAGE_TOWNHALL)->group(function () {
        Route::get('/manage/townhall-sessions/create', [TownHallManageController::class, 'create'])->name('townhall.create');
        Route::post('/manage/townhall-sessions', [TownHallManageController::class, 'store'])->name('townhall.store');
        Route::get('/manage/townhall-sessions/{session}/edit', [TownHallManageController::class, 'edit'])->name('townhall.edit');
        Route::put('/manage/townhall-sessions/{session}', [TownHallManageController::class, 'update'])->name('townhall.update');
        Route::post('/manage/townhall-sessions/{session}/reminder', [TownHallManageController::class, 'reminder'])->name('townhall.reminder');
    });

    Route::middleware('can:'.Permission::MANAGE_BROADCASTS)->group(function () {
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

    Route::middleware('can:'.Permission::MANAGE_USERS)->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('/roles', [RoleController::class, 'index'])->name('roles');
        Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });

    Route::get('/audit', [AuditController::class, 'index'])->middleware('can:'.Permission::VIEW_AUDIT)->name('audit');

    Route::middleware('can:'.Permission::MANAGE_SYSTEM)->group(function () {
        Route::get('/system', [SystemController::class, 'show'])->name('system');
        Route::post('/system/sync', [SystemController::class, 'sync'])->name('system.sync');
        Route::post('/system/reprocess', [SystemController::class, 'reprocess'])->name('system.reprocess');
        Route::post('/system/migrate', [SystemController::class, 'migrate'])->name('system.migrate');
        Route::post('/system/clear-rehearsal', [SystemController::class, 'clearRehearsal'])->name('system.clear-rehearsal');
        Route::post('/system/polling-units', [SystemController::class, 'importRegister'])->name('system.polling-units');
        Route::post('/system/push-keys', [SystemController::class, 'pushKeys'])->name('system.push-keys');
        Route::post('/system/data-view', [SystemController::class, 'dataView'])->name('system.data-view');
    });
});
