<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reminder;
use App\Models\TelegramChat;
use App\Models\TelegramUser;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $remindersByStatus = Reminder::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $remindersBySchedule = Reminder::query()
            ->selectRaw('schedule_type, COUNT(*) as aggregate')
            ->groupBy('schedule_type')
            ->pluck('aggregate', 'schedule_type');

        return response()->json([
            'data' => [
                'reminders' => [
                    'total' => Reminder::query()->count(),
                    'due_now' => Reminder::query()->due(now())->count(),
                    'by_status' => [
                        Reminder::STATUS_ACTIVE => (int) ($remindersByStatus[Reminder::STATUS_ACTIVE] ?? 0),
                        Reminder::STATUS_PAUSED => (int) ($remindersByStatus[Reminder::STATUS_PAUSED] ?? 0),
                        Reminder::STATUS_COMPLETED => (int) ($remindersByStatus[Reminder::STATUS_COMPLETED] ?? 0),
                    ],
                    'by_schedule' => [
                        Reminder::SCHEDULE_ONCE => (int) ($remindersBySchedule[Reminder::SCHEDULE_ONCE] ?? 0),
                        Reminder::SCHEDULE_INTERVAL => (int) ($remindersBySchedule[Reminder::SCHEDULE_INTERVAL] ?? 0),
                        Reminder::SCHEDULE_CRON => (int) ($remindersBySchedule[Reminder::SCHEDULE_CRON] ?? 0),
                    ],
                ],
                'users' => [
                    'total' => TelegramUser::query()->count(),
                    'active' => TelegramUser::query()->where('is_active', true)->count(),
                    'bots' => TelegramUser::query()->where('is_bot', true)->count(),
                ],
                'chats' => [
                    'total' => TelegramChat::query()->count(),
                    'active' => TelegramChat::query()->where('is_active', true)->count(),
                    'primary' => TelegramChat::query()->where('is_primary', true)->count(),
                ],
            ],
        ]);
    }
}
