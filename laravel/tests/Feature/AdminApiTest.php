<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\TelegramChat;
use App\Models\TelegramUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_endpoint_returns_aggregated_counts(): void
    {
        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001001001001,
            'type' => 'supergroup',
            'title' => 'Family',
            'is_primary' => true,
        ]);

        $user = TelegramUser::query()->create([
            'telegram_user_id' => 1001,
            'first_name' => 'Alice',
            'timezone' => 'Europe/Berlin',
        ]);

        Reminder::query()->create([
            'message' => 'Active due reminder',
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_INTERVAL,
            'interval_minutes' => 60,
            'timezone' => 'Europe/Berlin',
            'next_run_at' => now()->subMinute(),
        ]);

        Reminder::query()->create([
            'message' => 'Paused cron reminder',
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'status' => Reminder::STATUS_PAUSED,
            'schedule_type' => Reminder::SCHEDULE_CRON,
            'cron_expression' => '0 9 * * *',
            'timezone' => 'Europe/Berlin',
        ]);

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.reminders.total', 2)
            ->assertJsonPath('data.reminders.due_now', 1)
            ->assertJsonPath('data.reminders.by_status.active', 1)
            ->assertJsonPath('data.reminders.by_status.paused', 1)
            ->assertJsonPath('data.reminders.by_schedule.interval', 1)
            ->assertJsonPath('data.reminders.by_schedule.cron', 1)
            ->assertJsonPath('data.users.total', 1)
            ->assertJsonPath('data.chats.primary', 1);
    }

    public function test_reminders_index_supports_filters_and_embeds_related_entities(): void
    {
        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001001001002,
            'type' => 'supergroup',
            'title' => 'House',
            'is_primary' => true,
        ]);

        $alice = TelegramUser::query()->create([
            'telegram_user_id' => 2001,
            'first_name' => 'Alice',
            'timezone' => 'Europe/Berlin',
        ]);

        $bob = TelegramUser::query()->create([
            'telegram_user_id' => 2002,
            'first_name' => 'Bob',
            'timezone' => 'UTC',
        ]);

        $matchingReminder = Reminder::query()->create([
            'message' => 'Buy milk before school',
            'chat_id' => $chat->id,
            'user_id' => $alice->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_INTERVAL,
            'interval_minutes' => 120,
            'timezone' => 'Europe/Berlin',
            'next_run_at' => Carbon::create(2026, 3, 18, 10, 0, 0, 'UTC'),
        ]);

        Reminder::query()->create([
            'message' => 'Archive old invoices',
            'chat_id' => $chat->id,
            'user_id' => $bob->id,
            'status' => Reminder::STATUS_COMPLETED,
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'timezone' => 'UTC',
        ]);

        ReminderDelivery::query()->create([
            'reminder_id' => $matchingReminder->id,
            'chat_id' => $chat->id,
            'user_id' => $alice->id,
            'delivery_status' => ReminderDelivery::STATUS_SENT,
            'message_text' => 'Buy milk before school',
            'sent_at' => now(),
        ]);

        $response = $this->getJson(sprintf(
            '/api/admin/reminders?status=active&schedule_type=interval&user_id=%d&search=milk',
            $alice->id,
        ));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matchingReminder->id)
            ->assertJsonPath('data.0.deliveries_count', 1)
            ->assertJsonPath('data.0.user.display_name', 'Alice')
            ->assertJsonPath('data.0.chat.title', 'House');
    }

    public function test_user_detail_returns_counts_and_recent_reminders(): void
    {
        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001001001003,
            'type' => 'supergroup',
            'title' => 'Parents',
            'is_primary' => false,
        ]);

        $user = TelegramUser::query()->create([
            'telegram_user_id' => 3001,
            'username' => 'mom',
            'first_name' => 'Marta',
            'pseudonym' => 'Mama',
            'timezone' => 'Europe/Berlin',
        ]);

        Reminder::query()->create([
            'message' => 'Call grandma',
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_CRON,
            'cron_expression' => '0 18 * * *',
            'timezone' => 'Europe/Berlin',
            'next_run_at' => now()->addHour(),
        ]);

        Reminder::query()->create([
            'message' => 'Pause water timer',
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'status' => Reminder::STATUS_PAUSED,
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'timezone' => 'Europe/Berlin',
        ]);

        $response = $this->getJson('/api/admin/users/'.$user->id);

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.display_name', 'Mama')
            ->assertJsonPath('data.reminders_count', 2)
            ->assertJsonPath('data.active_reminders_count', 1)
            ->assertJsonPath('data.paused_reminders_count', 1)
            ->assertJsonCount(2, 'data.recent_reminders');
    }
}
