<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\TelegramChat;
use App\Models\TelegramUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_incoming_updates_and_syncs_chat_and_user(): void
    {
        config()->set('services.telegram.webhook_secret', 'test-secret');
        config()->set('services.telegram.primary_chat_id', null);

        $payload = [
            'update_id' => 9001,
            'message' => [
                'message_id' => 55,
                'date' => now()->timestamp,
                'chat' => [
                    'id' => -1001234567890,
                    'type' => 'supergroup',
                    'title' => 'Family',
                ],
                'from' => [
                    'id' => 321654,
                    'is_bot' => false,
                    'first_name' => 'Alex',
                    'username' => 'alex',
                    'language_code' => 'ru',
                ],
                'text' => 'hello',
            ],
        ];

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', $payload)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('telegram_chats', [
            'telegram_chat_id' => -1001234567890,
            'title' => 'Family',
            'is_primary' => true,
        ]);

        $this->assertDatabaseHas('telegram_users', [
            'telegram_user_id' => 321654,
            'username' => 'alex',
            'first_name' => 'Alex',
        ]);

        $this->assertDatabaseHas('telegram_updates', [
            'update_id' => 9001,
            'update_type' => 'message',
            'message_text' => 'hello',
        ]);
    }

    public function test_it_rejects_webhook_calls_with_invalid_secret(): void
    {
        config()->set('services.telegram.webhook_secret', 'test-secret');

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 1,
        ])->assertForbidden();
    }

    public function test_it_syncs_chat_and_users_from_my_chat_member_update(): void
    {
        config()->set('services.telegram.webhook_secret', 'test-secret');
        config()->set('services.telegram.primary_chat_id', null);

        $payload = [
            'update_id' => 9002,
            'my_chat_member' => [
                'chat' => [
                    'id' => -1002223334445,
                    'type' => 'supergroup',
                    'title' => 'Family Two',
                ],
                'from' => [
                    'id' => 987654,
                    'is_bot' => false,
                    'first_name' => 'Maria',
                    'username' => 'maria',
                    'language_code' => 'ru',
                ],
                'date' => now()->timestamp,
                'old_chat_member' => [
                    'status' => 'left',
                    'user' => [
                        'id' => 112233,
                        'is_bot' => true,
                        'first_name' => 'FamilyBot',
                        'username' => 'family_reminder_bot',
                    ],
                ],
                'new_chat_member' => [
                    'status' => 'member',
                    'user' => [
                        'id' => 112233,
                        'is_bot' => true,
                        'first_name' => 'FamilyBot',
                        'username' => 'family_reminder_bot',
                    ],
                ],
            ],
        ];

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', $payload)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('telegram_chats', [
            'telegram_chat_id' => -1002223334445,
            'title' => 'Family Two',
        ]);

        $this->assertDatabaseHas('telegram_users', [
            'telegram_user_id' => 987654,
            'username' => 'maria',
            'is_bot' => 0,
        ]);

        $this->assertDatabaseHas('telegram_users', [
            'telegram_user_id' => 112233,
            'username' => 'family_reminder_bot',
            'is_bot' => 1,
        ]);

        $this->assertDatabaseHas('telegram_updates', [
            'update_id' => 9002,
            'update_type' => 'my_chat_member',
            'message_text' => 'member',
        ]);
    }

    public function test_it_rejects_callback_from_non_target_user_for_personal_delivery(): void
    {
        config()->set('services.telegram.webhook_secret', 'test-secret');
        config()->set('services.telegram.primary_chat_id', null);
        config()->set('services.telegram.bot_token', 'test-token');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001234567890,
            'type' => 'supergroup',
            'title' => 'Family',
            'is_primary' => true,
        ]);

        $owner = TelegramUser::query()->create([
            'telegram_user_id' => 321654,
            'username' => 'child',
            'first_name' => 'Child',
            'is_active' => true,
        ]);

        $reminder = Reminder::query()->create([
            'message' => 'Пора на английский',
            'chat_id' => $chat->id,
            'user_id' => $owner->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'timezone' => 'Europe/Berlin',
            'next_run_at' => now()->addDay(),
            'ask_status' => true,
        ]);

        $delivery = ReminderDelivery::query()->create([
            'reminder_id' => $reminder->id,
            'chat_id' => $chat->id,
            'user_id' => $owner->id,
            'telegram_message_id' => 777,
            'delivery_status' => ReminderDelivery::STATUS_SENT,
            'message_text' => '@child Пора на английский',
            'sent_at' => now()->subMinute(),
        ]);

        $payload = [
            'update_id' => 9003,
            'callback_query' => [
                'id' => 'callback-unauthorized',
                'from' => [
                    'id' => 777000,
                    'is_bot' => false,
                    'first_name' => 'Other',
                    'username' => 'other',
                    'language_code' => 'ru',
                ],
                'message' => [
                    'message_id' => 777,
                    'chat' => [
                        'id' => -1001234567890,
                        'type' => 'supergroup',
                        'title' => 'Family',
                    ],
                ],
                'data' => sprintf('delivery:%d:done', $delivery->id),
            ],
        ];

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', $payload)
            ->assertOk()
            ->assertJson(['ok' => true]);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/answerCallbackQuery')
                && $data['callback_query_id'] === 'callback-unauthorized'
                && $data['text'] === 'Эта кнопка доступна только адресату напоминания.';
        });

        $delivery->refresh();
        $reminder->refresh();

        $this->assertSame(ReminderDelivery::STATUS_SENT, $delivery->delivery_status);
        $this->assertNull($delivery->acknowledged_at);
        $this->assertNull($reminder->snooze_until);

        $this->assertDatabaseHas('telegram_updates', [
            'update_id' => 9003,
            'update_type' => 'callback_query',
            'message_text' => sprintf('delivery:%d:done', $delivery->id),
        ]);
    }

    public function test_it_clears_buttons_after_valid_callback_and_ignores_repeat_press(): void
    {
        config()->set('services.telegram.webhook_secret', 'test-secret');
        config()->set('services.telegram.primary_chat_id', null);
        config()->set('services.telegram.bot_token', 'test-token');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001234567890,
            'type' => 'supergroup',
            'title' => 'Family',
            'is_primary' => true,
        ]);

        $owner = TelegramUser::query()->create([
            'telegram_user_id' => 321654,
            'username' => 'child',
            'first_name' => 'Child',
            'is_active' => true,
        ]);

        $reminder = Reminder::query()->create([
            'message' => 'Полить цветы',
            'chat_id' => $chat->id,
            'user_id' => $owner->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'timezone' => 'Europe/Berlin',
            'next_run_at' => now()->addDay(),
            'ask_status' => true,
        ]);

        $delivery = ReminderDelivery::query()->create([
            'reminder_id' => $reminder->id,
            'chat_id' => $chat->id,
            'user_id' => $owner->id,
            'telegram_message_id' => 888,
            'delivery_status' => ReminderDelivery::STATUS_SENT,
            'message_text' => '@child Полить цветы',
            'sent_at' => now()->subMinute(),
        ]);

        $payload = [
            'callback_query' => [
                'from' => [
                    'id' => 321654,
                    'is_bot' => false,
                    'first_name' => 'Child',
                    'username' => 'child',
                    'language_code' => 'ru',
                ],
                'message' => [
                    'message_id' => 888,
                    'chat' => [
                        'id' => -1001234567890,
                        'type' => 'supergroup',
                        'title' => 'Family',
                    ],
                ],
                'data' => sprintf('delivery:%d:done', $delivery->id),
            ],
        ];

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', array_merge($payload, [
                'update_id' => 9004,
                'callback_query' => array_merge($payload['callback_query'], ['id' => 'callback-first']),
            ]))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $delivery->refresh();
        $firstAcknowledgedAt = $delivery->acknowledged_at;

        $this->assertSame(ReminderDelivery::STATUS_DONE, $delivery->delivery_status);
        $this->assertNotNull($firstAcknowledgedAt);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', array_merge($payload, [
                'update_id' => 9005,
                'callback_query' => array_merge($payload['callback_query'], ['id' => 'callback-second']),
            ]))
            ->assertOk()
            ->assertJson(['ok' => true]);

        Http::assertSentCount(4);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/sendMessage')
                && ($data['disable_notification'] ?? false) === true
                && $data['text'] === "поздравляю с выполнением задачи:\nПолить цветы"
                && (($data['parse_mode'] ?? null) === 'HTML');
        });
        Http::assertSent(function (Request $request) use ($chat): bool {
            $data = $request->data();

            return str_contains($request->url(), '/editMessageReplyMarkup')
                && (int) $data['chat_id'] === $chat->telegram_chat_id
                && (int) $data['message_id'] === 888
                && data_get($data, 'reply_markup.inline_keyboard') === [];
        });
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/answerCallbackQuery')
                && $data['callback_query_id'] === 'callback-first'
                && $data['text'] === 'Статус сохранен: выполнено.';
        });
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/answerCallbackQuery')
                && $data['callback_query_id'] === 'callback-second'
                && $data['text'] === 'Статус уже сохранен.';
        });

        $delivery->refresh();

        $this->assertSame(ReminderDelivery::STATUS_DONE, $delivery->delivery_status);
        $this->assertTrue($delivery->acknowledged_at?->equalTo($firstAcknowledgedAt));
    }
}
