<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\TelegramChat;
use App\Models\TelegramUser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReminderCrudApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_one_time_reminder_from_admin_payload(): void
    {
        config()->set('app.timezone', 'UTC');

        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1002002002001,
            'type' => 'supergroup',
            'title' => 'Family',
            'is_primary' => true,
        ]);

        $user = TelegramUser::query()->create([
            'telegram_user_id' => 4001,
            'first_name' => 'Nina',
            'timezone' => 'Europe/Berlin',
        ]);

        $scheduledFor = CarbonImmutable::now('Europe/Berlin')->addDay()->setTime(9, 30);

        $response = $this->postJson('/api/admin/reminders', [
            'message' => 'Take vitamins',
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'mention_override' => '',
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'timezone' => 'Europe/Berlin',
            'ask_status' => true,
            'scheduled_for_local' => $scheduledFor->format('Y-m-d\TH:i'),
        ]);

        $expectedNextRunAt = $scheduledFor->setTimezone('UTC')->toISOString();

        $response->assertCreated()
            ->assertJsonPath('data.message', 'Take vitamins')
            ->assertJsonPath('data.schedule_type', Reminder::SCHEDULE_ONCE)
            ->assertJsonPath('data.ask_status', true)
            ->assertJsonPath('data.next_run_at', $expectedNextRunAt)
            ->assertJsonPath('data.starts_at', null);

        $reminder = Reminder::query()->sole();

        $this->assertSame($chat->id, $reminder->chat_id);
        $this->assertSame($user->id, $reminder->user_id);
        $this->assertNull($reminder->starts_at);
        $this->assertSame($expectedNextRunAt, $reminder->next_run_at?->toISOString());
        $this->assertNull($reminder->snooze_until);
        $this->assertSame(['source' => 'admin_api', 'created_via' => 'admin_api'], $reminder->meta);
    }

    public function test_it_updates_reminder_and_recalculates_interval_schedule(): void
    {
        config()->set('app.timezone', 'UTC');
        $this->travelTo(CarbonImmutable::create(2026, 3, 18, 10, 5, 0, 'UTC'));

        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1002002002002,
            'type' => 'supergroup',
            'title' => 'House',
            'is_primary' => true,
        ]);

        $user = TelegramUser::query()->create([
            'telegram_user_id' => 4002,
            'first_name' => 'Maks',
            'timezone' => 'Europe/Berlin',
        ]);

        $reminder = Reminder::query()->create([
            'message' => 'Old message',
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'timezone' => 'Europe/Berlin',
            'next_run_at' => CarbonImmutable::create(2026, 3, 20, 8, 30, 0, 'UTC'),
            'snooze_until' => CarbonImmutable::create(2026, 3, 18, 10, 30, 0, 'UTC'),
            'ask_status' => true,
            'meta' => ['source' => 'draft'],
        ]);

        $response = $this->patchJson('/api/admin/reminders/'.$reminder->id, [
            'message' => 'Water the tomatoes',
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'mention_override' => 'Garden team',
            'status' => Reminder::STATUS_PAUSED,
            'schedule_type' => Reminder::SCHEDULE_INTERVAL,
            'timezone' => 'Europe/Berlin',
            'ask_status' => false,
            'starts_at_local' => '2026-03-18T10:00',
            'interval_minutes' => 30,
        ]);

        $expectedStartsAt = CarbonImmutable::create(2026, 3, 18, 10, 0, 0, 'Europe/Berlin')
            ->setTimezone('UTC')
            ->toISOString();
        $expectedNextRunAt = CarbonImmutable::create(2026, 3, 18, 11, 30, 0, 'Europe/Berlin')
            ->setTimezone('UTC')
            ->toISOString();

        $response->assertOk()
            ->assertJsonPath('data.message', 'Water the tomatoes')
            ->assertJsonPath('data.status', Reminder::STATUS_PAUSED)
            ->assertJsonPath('data.schedule_type', Reminder::SCHEDULE_INTERVAL)
            ->assertJsonPath('data.interval_minutes', 30)
            ->assertJsonPath('data.starts_at', $expectedStartsAt)
            ->assertJsonPath('data.next_run_at', $expectedNextRunAt)
            ->assertJsonPath('data.mention_override', 'Garden team')
            ->assertJsonPath('data.ask_status', false);

        $reminder->refresh();

        $this->assertSame(Reminder::STATUS_PAUSED, $reminder->status);
        $this->assertSame('Water the tomatoes', $reminder->message);
        $this->assertSame('Garden team', $reminder->mention_override);
        $this->assertSame($expectedStartsAt, $reminder->starts_at?->toISOString());
        $this->assertSame($expectedNextRunAt, $reminder->next_run_at?->toISOString());
        $this->assertNull($reminder->snooze_until);
        $this->assertSame(['source' => 'draft'], $reminder->meta);
    }

    public function test_it_deletes_reminder(): void
    {
        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1002002002003,
            'type' => 'supergroup',
            'title' => 'Parents',
            'is_primary' => true,
        ]);

        $reminder = Reminder::query()->create([
            'message' => 'Delete me',
            'chat_id' => $chat->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'timezone' => 'Europe/Berlin',
            'next_run_at' => now()->addDay(),
            'ask_status' => false,
        ]);

        $this->deleteJson('/api/admin/reminders/'.$reminder->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('reminders', ['id' => $reminder->id]);
    }

    public function test_it_requires_chat_or_primary_chat_target_for_creation(): void
    {
        config()->set('services.telegram.primary_chat_id', null);

        $response = $this->postJson('/api/admin/reminders', [
            'message' => 'No target chat',
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'timezone' => 'Europe/Berlin',
            'ask_status' => false,
            'scheduled_for_local' => '2026-03-20T09:30',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['chat_id']);
    }
}
