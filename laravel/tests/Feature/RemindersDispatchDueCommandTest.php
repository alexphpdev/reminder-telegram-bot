<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\TelegramChat;
use App\Models\TelegramUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemindersDispatchDueCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_due_reminders_and_updates_delivery_state(): void
    {
        config()->set('services.telegram.bot_token', 'test-token');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 777,
                ],
            ]),
        ]);

        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001234567890,
            'type' => 'supergroup',
            'title' => 'Family',
            'is_primary' => true,
        ]);

        $user = TelegramUser::query()->create([
            'telegram_user_id' => 321654,
            'username' => 'child',
            'first_name' => 'Child',
        ]);

        $reminder = Reminder::query()->create([
            'message' => 'Пора переставить будильник на послезавтра.',
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_INTERVAL,
            'interval_minutes' => 2880,
            'timezone' => 'Europe/Berlin',
            'next_run_at' => now()->subMinute(),
            'ask_status' => true,
        ]);

        $this->artisan('reminders:dispatch-due')
            ->assertSuccessful();

        Http::assertSent(function (Request $request) use ($chat): bool {
            $data = $request->data();
            $text = (string) ($data['text'] ?? '');

            return str_contains($request->url(), '/sendMessage')
                && (int) $data['chat_id'] === $chat->telegram_chat_id
                && $data['parse_mode'] === 'HTML'
                && str_contains($text, '<a href="tg://user?id=321654">Child</a>')
                && str_contains($text, 'Пора переставить будильник')
                && str_starts_with(
                    $data['reply_markup']['inline_keyboard'][0][0]['callback_data'],
                    'delivery:',
                );
        });

        $reminder->refresh();

        $this->assertNotNull($reminder->last_sent_at);
        $this->assertNotNull($reminder->next_run_at);
        $this->assertTrue($reminder->next_run_at->greaterThan(now()->addDay()));

        $delivery = ReminderDelivery::query()->first();

        $this->assertNotNull($delivery);
        $this->assertSame(ReminderDelivery::STATUS_SENT, $delivery->delivery_status);
        $this->assertSame(777, $delivery->telegram_message_id);
        $this->assertNull($delivery->error_message);
    }

    public function test_it_stores_interval_schedule_timestamps_in_app_timezone(): void
    {
        config()->set('app.timezone', 'UTC');
        config()->set('services.telegram.bot_token', 'test-token');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 779,
                ],
            ]),
        ]);

        $dueAt = Carbon::create(2026, 3, 7, 8, 0, 0, 'UTC');
        $this->travelTo($dueAt);

        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001234567890,
            'type' => 'supergroup',
            'title' => 'Family',
            'is_primary' => true,
        ]);

        $user = TelegramUser::query()->create([
            'telegram_user_id' => 321654,
            'username' => 'child',
            'first_name' => 'Child',
        ]);

        $reminder = Reminder::query()->create([
            'message' => 'Пора на тренировку.',
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_INTERVAL,
            'interval_minutes' => 1440,
            'timezone' => 'Europe/Berlin',
            'next_run_at' => $dueAt,
            'ask_status' => false,
        ]);

        $this->artisan('reminders:dispatch-due')->assertSuccessful();

        $reminder->refresh();

        $this->assertTrue($reminder->last_sent_at->equalTo($dueAt));
        $this->assertSame('UTC', $reminder->last_sent_at->getTimezone()->getName());
        $this->assertTrue($reminder->next_run_at->equalTo(Carbon::create(2026, 3, 8, 8, 0, 0, 'UTC')));
        $this->assertSame('UTC', $reminder->next_run_at->getTimezone()->getName());
    }

    public function test_it_stores_cron_next_run_in_app_timezone_while_evaluating_cron_in_reminder_timezone(): void
    {
        config()->set('app.timezone', 'UTC');
        config()->set('services.telegram.bot_token', 'test-token');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 780,
                ],
            ]),
        ]);

        $dueAt = Carbon::create(2026, 3, 7, 8, 0, 0, 'UTC');
        $this->travelTo($dueAt);

        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001234567890,
            'type' => 'supergroup',
            'title' => 'Family',
            'is_primary' => true,
        ]);

        $user = TelegramUser::query()->create([
            'telegram_user_id' => 321654,
            'username' => 'child',
            'first_name' => 'Child',
        ]);

        $reminder = Reminder::query()->create([
            'message' => 'Пора на зарядку.',
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_CRON,
            'cron_expression' => '0 9 * * *',
            'timezone' => 'Europe/Berlin',
            'next_run_at' => $dueAt,
            'ask_status' => false,
        ]);

        $this->artisan('reminders:dispatch-due')->assertSuccessful();

        $reminder->refresh();

        $this->assertSame('0 9 * * *', $reminder->cron_expression);
        $this->assertTrue($reminder->last_sent_at->equalTo($dueAt));
        $this->assertSame('UTC', $reminder->last_sent_at->getTimezone()->getName());
        $this->assertTrue($reminder->next_run_at->equalTo(Carbon::create(2026, 3, 8, 8, 0, 0, 'UTC')));
        $this->assertSame('UTC', $reminder->next_run_at->getTimezone()->getName());
    }

    public function test_it_prefers_pseudonym_for_generated_user_mentions(): void
    {
        config()->set('services.telegram.bot_token', 'test-token');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 778,
                ],
            ]),
        ]);

        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001234567890,
            'type' => 'supergroup',
            'title' => 'Family',
            'is_primary' => true,
        ]);

        $user = TelegramUser::query()->create([
            'telegram_user_id' => 321654,
            'username' => 'child',
            'first_name' => 'Child',
            'pseudonym' => 'Kiddo',
        ]);

        Reminder::query()->create([
            'message' => 'Пора на английский',
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_INTERVAL,
            'interval_minutes' => 1440,
            'timezone' => 'Europe/Berlin',
            'next_run_at' => now()->subMinute(),
            'ask_status' => true,
        ]);

        $this->artisan('reminders:dispatch-due')
            ->assertSuccessful();

        Http::assertSent(function (Request $request) use ($chat): bool {
            $data = $request->data();
            $text = (string) ($data['text'] ?? '');

            return str_contains($request->url(), '/sendMessage')
                && (int) $data['chat_id'] === $chat->telegram_chat_id
                && str_contains($text, '<a href="tg://user?id=321654">Kiddo</a>')
                && ! str_contains($text, '<a href="tg://user?id=321654">Child</a>');
        });
    }

    public function test_it_snoozes_a_reminder_for_thirty_minutes_when_later_is_pressed(): void
    {
        config()->set('app.timezone', 'Europe/Berlin');
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.webhook_secret', '');

        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 777,
                    ],
                ])
                ->push([
                    'ok' => true,
                    'result' => true,
                ])
                ->push([
                    'ok' => true,
                    'result' => true,
                ])
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 778,
                    ],
                ]),
        ]);

        $startedAt = Carbon::create(2026, 3, 7, 20, 0, 0, 'Europe/Berlin');
        $this->travelTo($startedAt);

        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001234567890,
            'type' => 'supergroup',
            'title' => 'Family',
            'is_primary' => true,
        ]);

        $user = TelegramUser::query()->create([
            'telegram_user_id' => 321654,
            'username' => 'child',
            'first_name' => 'Child',
        ]);

        $reminder = Reminder::query()->create([
            'message' => 'Пора переставить будильник на послезавтра.',
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_INTERVAL,
            'interval_minutes' => 2880,
            'timezone' => 'Europe/Berlin',
            'next_run_at' => now()->subMinute(),
            'ask_status' => true,
        ]);

        $this->artisan('reminders:dispatch-due')->assertSuccessful();

        $firstDelivery = ReminderDelivery::query()->firstOrFail();
        $originalNextRunAt = $reminder->fresh()->next_run_at;

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 2001,
            'callback_query' => [
                'id' => 'callback-1',
                'from' => [
                    'id' => 321654,
                    'is_bot' => false,
                    'first_name' => 'Child',
                    'username' => 'child',
                ],
                'message' => [
                    'message_id' => 777,
                    'chat' => [
                        'id' => -1001234567890,
                        'type' => 'supergroup',
                        'title' => 'Family',
                    ],
                ],
                'data' => sprintf('delivery:%d:later', $firstDelivery->id),
            ],
        ])->assertOk();

        Http::assertSent(function (Request $request) use ($chat): bool {
            $data = $request->data();

            return str_contains($request->url(), '/editMessageReplyMarkup')
                && (int) $data['chat_id'] === $chat->telegram_chat_id
                && (int) $data['message_id'] === 777
                && data_get($data, 'reply_markup.inline_keyboard') === [];
        });

        $reminder->refresh();
        $firstDelivery->refresh();

        $this->assertNotNull($reminder->snooze_until);
        $this->assertNotNull($firstDelivery->acknowledged_at);
        $this->assertSame(1800, (int) $firstDelivery->acknowledged_at->diffInSeconds($reminder->snooze_until));
        $this->assertTrue($reminder->next_run_at->equalTo($originalNextRunAt));

        $this->travelTo($startedAt->copy()->addMinutes(31));

        $this->artisan('reminders:dispatch-due')->assertSuccessful();

        $reminder->refresh();

        $this->assertNull($reminder->snooze_until);
        $this->assertTrue($reminder->next_run_at->equalTo($originalNextRunAt));
        $this->assertCount(2, ReminderDelivery::query()->get());
    }

    public function test_it_resends_unanswered_status_reminder_after_timeout_and_expires_previous_delivery(): void
    {
        config()->set('app.timezone', 'Europe/Berlin');
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.status_response_timeout_minutes', 15);

        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 778,
                    ],
                ])
                ->push([
                    'ok' => true,
                    'result' => true,
                ]),
        ]);

        $startedAt = Carbon::create(2026, 3, 7, 20, 0, 0, 'Europe/Berlin');
        $this->travelTo($startedAt);

        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001234567890,
            'type' => 'supergroup',
            'title' => 'Family',
            'is_primary' => true,
        ]);

        $user = TelegramUser::query()->create([
            'telegram_user_id' => 321654,
            'username' => 'child',
            'first_name' => 'Child',
        ]);

        $reminder = Reminder::query()->create([
            'message' => 'Пора переставить будильник на послезавтра.',
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_INTERVAL,
            'interval_minutes' => 2880,
            'timezone' => 'Europe/Berlin',
            'next_run_at' => now()->addDay(),
            'ask_status' => true,
        ]);

        $staleDelivery = ReminderDelivery::query()->create([
            'reminder_id' => $reminder->id,
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'telegram_message_id' => 777,
            'delivery_status' => ReminderDelivery::STATUS_SENT,
            'message_text' => '@child Пора переставить будильник на послезавтра.',
            'response_payload' => [
                'ok' => true,
                'result' => [
                    'message_id' => 777,
                    'chat' => [
                        'id' => -1001234567890,
                    ],
                ],
            ],
            'sent_at' => now()->subMinutes(16),
        ]);

        $this->artisan('reminders:dispatch-due')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) use ($chat, $staleDelivery): bool {
            $data = $request->data();

            return str_contains($request->url(), '/sendMessage')
                && (int) $data['chat_id'] === $chat->telegram_chat_id
                && $data['text'] === $staleDelivery->message_text
                && str_starts_with(
                    $data['reply_markup']['inline_keyboard'][0][0]['callback_data'],
                    'delivery:',
                );
        });
        Http::assertSent(function (Request $request) use ($chat): bool {
            $data = $request->data();

            return str_contains($request->url(), '/deleteMessage')
                && (int) $data['chat_id'] === $chat->telegram_chat_id
                && (int) $data['message_id'] === 777;
        });

        $staleDelivery->refresh();

        $this->assertSame(ReminderDelivery::STATUS_EXPIRED, $staleDelivery->delivery_status);

        $replacementDelivery = ReminderDelivery::query()
            ->where('id', '!=', $staleDelivery->id)
            ->firstOrFail();

        $this->assertSame(ReminderDelivery::STATUS_SENT, $replacementDelivery->delivery_status);
        $this->assertSame(778, $replacementDelivery->telegram_message_id);
        $this->assertSame($staleDelivery->message_text, $replacementDelivery->message_text);

        $this->assertSame($replacementDelivery->id, data_get($staleDelivery->status_payload, '_expired.replaced_by_delivery_id'));
        $this->assertTrue((bool) data_get($staleDelivery->status_payload, '_expired.message_delete_attempted'));
        $this->assertTrue((bool) data_get($staleDelivery->status_payload, '_expired.message_deleted'));
    }
}
