<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminTelegramUserDetailResource;
use App\Http\Resources\Admin\AdminTelegramUserSummaryResource;
use App\Models\Reminder;
use App\Models\TelegramUser;
use Illuminate\Http\Request;

class AdminTelegramUserController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        $query = TelegramUser::query()
            ->withCount('reminders')
            ->withCount([
                'reminders as active_reminders_count' => fn ($builder) => $builder->where('status', Reminder::STATUS_ACTIVE),
                'reminders as paused_reminders_count' => fn ($builder) => $builder->where('status', Reminder::STATUS_PAUSED),
                'reminders as completed_reminders_count' => fn ($builder) => $builder->where('status', Reminder::STATUS_COMPLETED),
            ])
            ->when($search !== '', function ($builder) use ($search): void {
                $builder->where(function ($nested) use ($search): void {
                    $nested
                        ->where('username', 'like', '%'.$search.'%')
                        ->orWhere('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%')
                        ->orWhere('pseudonym', 'like', '%'.$search.'%');

                    if (preg_match('/^\d+$/', $search) === 1) {
                        $nested->orWhere('telegram_user_id', (int) $search);
                    }
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        return AdminTelegramUserSummaryResource::collection(
            $query->paginate((int) ($validated['per_page'] ?? 20))->withQueryString()
        );
    }

    public function show(TelegramUser $telegramUser): AdminTelegramUserDetailResource
    {
        $telegramUser->loadCount('reminders');
        $telegramUser->loadCount([
            'reminders as active_reminders_count' => fn ($builder) => $builder->where('status', Reminder::STATUS_ACTIVE),
            'reminders as paused_reminders_count' => fn ($builder) => $builder->where('status', Reminder::STATUS_PAUSED),
            'reminders as completed_reminders_count' => fn ($builder) => $builder->where('status', Reminder::STATUS_COMPLETED),
        ]);
        $telegramUser->load([
            'reminders' => fn ($builder) => $builder
                ->with(['chat', 'user'])
                ->withCount('deliveries')
                ->orderByRaw('CASE WHEN next_run_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('next_run_at')
                ->orderByDesc('updated_at')
                ->limit(5),
        ]);

        return new AdminTelegramUserDetailResource($telegramUser);
    }
}
