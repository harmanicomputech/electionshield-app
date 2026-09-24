<?php

use App\Http\Controllers\Api\UssdEventController;
use App\Http\Middleware\VerifyUssdWebhook;
use Illuminate\Support\Facades\Route;

// Events pushed by the Election Shield USSD service (DASHBOARD_WEBHOOK_URL there).
Route::post('/ussd-events', UssdEventController::class)
    ->middleware(VerifyUssdWebhook::class)
    ->name('ussd-events');
