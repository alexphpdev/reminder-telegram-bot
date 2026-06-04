<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminReminderController;
use App\Http\Controllers\Admin\AdminTelegramUserController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->name('telegram.webhook');

Route::post('/socket-state', function() {
//    logger()->emergency(print_r(request()->all(), true));
    return response('', 200);
});

Route::prefix('admin')->group(function (): void {
    Route::get('/dashboard', AdminDashboardController::class)
        ->name('admin.dashboard');

    Route::get('/reminders', [AdminReminderController::class, 'index'])
        ->name('admin.reminders.index');
    Route::post('/reminders', [AdminReminderController::class, 'store'])
        ->name('admin.reminders.store');
    Route::get('/reminders/{reminder}', [AdminReminderController::class, 'show'])
        ->name('admin.reminders.show');
    Route::patch('/reminders/{reminder}', [AdminReminderController::class, 'update'])
        ->name('admin.reminders.update');
    Route::delete('/reminders/{reminder}', [AdminReminderController::class, 'destroy'])
        ->name('admin.reminders.destroy');

    Route::get('/users', [AdminTelegramUserController::class, 'index'])
        ->name('admin.users.index');
    Route::get('/users/{telegramUser}', [AdminTelegramUserController::class, 'show'])
        ->name('admin.users.show');
});
