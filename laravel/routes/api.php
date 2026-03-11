<?php

use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->name('telegram.webhook');

Route::post('/socket-state', function() {
//    logger()->emergency(print_r(request()->all(), true));
    return response('', 200);
});

