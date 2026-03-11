<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\TelegramChat;
use App\Models\TelegramUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MyRemindersListTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_only_future_user_reminders_and_includes_statuses_when_any_paused(): void
    {
        config()->set('services.telegram.webhook_secret', 'test-secret');
        config()->set('services.telegram.primary_chat_id', null);
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.message_locale', 'ru');

        $startedAt = Carbon::create(2026, 3, 8, 10, 0, 0, 'UTC');
        $this->travelTo($startedAt);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/sendMessage')) {
                return Http::response([
                    'ok' => true,
                    'result' => [
                        'message_id' => 901,
                    ],
                ]);
            }

            return Http::response([
                'ok' => true,
                'result' => true,
            ]);
        });

        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001234567890,
            'type' => 'supergroup',
            'title' => 'Family',
            'is_primary' => true,
        ]);

        $user = TelegramUser::query()->create([
            'telegram_user_id' => 321654,
            'username' => 'alex',
            'first_name' => 'Alex',
        ]);

        $otherUser = TelegramUser::query()->create([
            'telegram_user_id' => 999888,
            'username' => 'other',
            'first_name' => 'Other',
        ]);

        Reminder::query()->create([
            'message' => 'Buy milk',
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'timezone' => 'UTC',
            'next_run_at' => now()->addHours(2),
        ]);

        Reminder::query()->create([
            'message' => 'Water flowers',
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'status' => Reminder::STATUS_PAUSED,
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'timezone' => 'UTC',
            'next_run_at' => now()->addHours(3),
        ]);

        Reminder::query()->create([
            'message' => 'Past task',
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'timezone' => 'UTC',
            'next_run_at' => now()->subMinute(),
        ]);

        Reminder::query()->create([
            'message' => 'Other user task',
            'chat_id' => $chat->id,
            'user_id' => $otherUser->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'timezone' => 'UTC',
            'next_run_at' => now()->addHours(2),
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 93001,
                'message' => [
                    'message_id' => 41,
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
                    'text' => '📋 Мои напоминания',
                ],
            ])
            ->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();
            $text = (string) ($data['text'] ?? '');
            $firstCardCallback = (string) data_get($data, 'reply_markup.inline_keyboard.0.0.callback_data');

            return str_contains($request->url(), '/sendMessage')
                && ($data['disable_notification'] ?? false) === true
                && ($data['parse_mode'] ?? null) === 'HTML'
                && str_contains($text, 'Твои будущие напоминания:')
                && str_contains($text, 'Buy milk')
                && str_contains($text, 'Water flowers')
                && ! str_contains($text, 'Past task')
                && ! str_contains($text, 'Other user task')
                && str_contains($text, '🟢 <b>Активно</b>')
                && str_contains($text, '🟡 <b>Пауза</b>')
                && data_get($data, 'reply_markup.inline_keyboard.0.0.text') !== 'Закрыть'
                && (bool) preg_match('/^myreminders:card:321654:\d+:41$/', $firstCardCallback)
                && data_get($data, 'reply_markup.inline_keyboard.2.0.text') === 'Закрыть'
                && data_get($data, 'reply_markup.inline_keyboard.2.0.callback_data') === 'myreminders:close:321654:41';
        });
    }

    public function test_it_hides_status_labels_when_all_future_reminders_are_active(): void
    {
        config()->set('services.telegram.webhook_secret', 'test-secret');
        config()->set('services.telegram.primary_chat_id', null);
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.message_locale', 'ru');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 911,
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
            'username' => 'alex',
            'first_name' => 'Alex',
        ]);

        Reminder::query()->create([
            'message' => 'English class',
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'timezone' => 'UTC',
            'next_run_at' => now()->addHour(),
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 93002,
                'message' => [
                    'message_id' => 42,
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
                    'text' => '📋 Мои напоминания',
                ],
            ])
            ->assertOk();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();
            $text = (string) ($data['text'] ?? '');

            return str_contains($request->url(), '/sendMessage')
                && str_contains($text, 'English class')
                && ! str_contains($text, '🟢 <b>Активно</b>')
                && ! str_contains($text, '🟡 <b>Пауза</b>');
        });
    }

    public function test_it_renders_list_time_in_reminder_timezone(): void
    {
        config()->set('app.timezone', 'UTC');
        config()->set('services.telegram.webhook_secret', 'test-secret');
        config()->set('services.telegram.primary_chat_id', null);
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.message_locale', 'ru');

        $startedAt = Carbon::create(2026, 3, 8, 8, 0, 0, 'UTC');
        $this->travelTo($startedAt);

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 912,
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
            'username' => 'alex',
            'first_name' => 'Alex',
        ]);

        Reminder::query()->create([
            'message' => 'Kyiv time test',
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'timezone' => 'Europe/Kyiv',
            'next_run_at' => Carbon::create(2026, 3, 8, 10, 0, 0, 'UTC'),
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 930021,
                'message' => [
                    'message_id' => 45,
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
                    'text' => '📋 Мои напоминания',
                ],
            ])
            ->assertOk();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();
            $text = (string) ($data['text'] ?? '');
            $buttonLabel = (string) data_get($data, 'reply_markup.inline_keyboard.0.0.text');

            return str_contains($request->url(), '/sendMessage')
                && str_contains($text, '<b>08.03.2026 12:00</b>')
                && ! str_contains($text, '<b>08.03.2026 10:00</b>')
                && str_contains($buttonLabel, '08.03 12:00');
        });
    }

    public function test_it_closes_user_reminders_message_with_close_button(): void
    {
        config()->set('services.telegram.webhook_secret', 'test-secret');
        config()->set('services.telegram.primary_chat_id', null);
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.message_locale', 'ru');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 93003,
                'callback_query' => [
                    'id' => 'my-reminders-close',
                    'from' => [
                        'id' => 321654,
                        'is_bot' => false,
                        'first_name' => 'Alex',
                        'username' => 'alex',
                        'language_code' => 'ru',
                    ],
                    'message' => [
                        'message_id' => 901,
                        'chat' => [
                            'id' => -1001234567890,
                            'type' => 'supergroup',
                            'title' => 'Family',
                        ],
                    ],
                    'data' => 'myreminders:close:321654:41',
                ],
            ])
            ->assertOk();

        Http::assertSentCount(3);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/deleteMessage')
                && (int) $data['chat_id'] === -1001234567890
                && (int) $data['message_id'] === 901;
        });
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/deleteMessage')
                && (int) $data['chat_id'] === -1001234567890
                && (int) $data['message_id'] === 41;
        });
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/answerCallbackQuery')
                && $data['callback_query_id'] === 'my-reminders-close'
                && $data['text'] === 'Список закрыт.';
        });
    }

    public function test_it_opens_reminder_card_from_list_callback(): void
    {
        config()->set('services.telegram.webhook_secret', 'test-secret');
        config()->set('services.telegram.primary_chat_id', null);
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.message_locale', 'ru');

        $startedAt = Carbon::create(2026, 3, 8, 10, 0, 0, 'UTC');
        $this->travelTo($startedAt);

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $user = TelegramUser::query()->create([
            'telegram_user_id' => 321654,
            'username' => 'alex',
            'first_name' => 'Alex',
        ]);

        $reminder = Reminder::query()->create([
            'message' => 'Practice English',
            'user_id' => $user->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_INTERVAL,
            'interval_minutes' => 1440,
            'timezone' => 'Europe/Kyiv',
            'next_run_at' => now()->addHours(9),
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 93004,
                'callback_query' => [
                    'id' => 'my-reminders-card',
                    'from' => [
                        'id' => 321654,
                        'is_bot' => false,
                        'first_name' => 'Alex',
                        'username' => 'alex',
                        'language_code' => 'ru',
                    ],
                    'message' => [
                        'message_id' => 902,
                        'chat' => [
                            'id' => -1001234567890,
                            'type' => 'supergroup',
                            'title' => 'Family',
                        ],
                    ],
                    'data' => sprintf('myreminders:card:321654:%d', $reminder->id),
                ],
            ])
            ->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) use ($reminder): bool {
            $data = $request->data();
            $text = (string) ($data['text'] ?? '');

            return str_contains($request->url(), '/editMessageText')
                && (int) $data['chat_id'] === -1001234567890
                && (int) $data['message_id'] === 902
                && ($data['parse_mode'] ?? null) === 'HTML'
                && str_contains($text, 'Карточка напоминания:')
                && str_contains($text, 'Practice English')
                && str_contains($text, 'каждые 1440 мин')
                && data_get($data, 'reply_markup.inline_keyboard.0.0.text') === 'Пауза'
                && data_get($data, 'reply_markup.inline_keyboard.0.0.callback_data') === sprintf('myreminders:toggle:321654:%d', $reminder->id)
                && data_get($data, 'reply_markup.inline_keyboard.0.1.text') === 'Удалить'
                && data_get($data, 'reply_markup.inline_keyboard.0.1.callback_data') === sprintf('myreminders:delete:321654:%d', $reminder->id)
                && data_get($data, 'reply_markup.inline_keyboard.1.0.text') === 'Назад'
                && data_get($data, 'reply_markup.inline_keyboard.1.0.callback_data') === 'myreminders:back:321654'
                && data_get($data, 'reply_markup.inline_keyboard.1.1.text') === 'Закрыть'
                && data_get($data, 'reply_markup.inline_keyboard.1.1.callback_data') === 'myreminders:close:321654';
        });
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/answerCallbackQuery')
                && $data['callback_query_id'] === 'my-reminders-card'
                && $data['text'] === 'Карточка открыта.';
        });
    }

    public function test_it_returns_back_to_list_from_reminder_card(): void
    {
        config()->set('services.telegram.webhook_secret', 'test-secret');
        config()->set('services.telegram.primary_chat_id', null);
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.message_locale', 'ru');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $user = TelegramUser::query()->create([
            'telegram_user_id' => 321654,
            'username' => 'alex',
            'first_name' => 'Alex',
        ]);

        Reminder::query()->create([
            'message' => 'Water flowers',
            'user_id' => $user->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'timezone' => 'UTC',
            'next_run_at' => now()->addHours(3),
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 93005,
                'callback_query' => [
                    'id' => 'my-reminders-back',
                    'from' => [
                        'id' => 321654,
                        'is_bot' => false,
                        'first_name' => 'Alex',
                        'username' => 'alex',
                        'language_code' => 'ru',
                    ],
                    'message' => [
                        'message_id' => 903,
                        'chat' => [
                            'id' => -1001234567890,
                            'type' => 'supergroup',
                            'title' => 'Family',
                        ],
                    ],
                    'data' => 'myreminders:back:321654',
                ],
            ])
            ->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();
            $text = (string) ($data['text'] ?? '');
            $firstCardCallback = (string) data_get($data, 'reply_markup.inline_keyboard.0.0.callback_data');

            return str_contains($request->url(), '/editMessageText')
                && str_contains($text, 'Твои будущие напоминания:')
                && str_contains($text, 'Water flowers')
                && str_starts_with($firstCardCallback, 'myreminders:card:321654:')
                && data_get($data, 'reply_markup.inline_keyboard.1.0.text') === 'Закрыть'
                && data_get($data, 'reply_markup.inline_keyboard.1.0.callback_data') === 'myreminders:close:321654';
        });
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/answerCallbackQuery')
                && $data['callback_query_id'] === 'my-reminders-back'
                && $data['text'] === 'Список открыт.';
        });
    }

    public function test_it_toggles_reminder_status_from_card(): void
    {
        config()->set('services.telegram.webhook_secret', 'test-secret');
        config()->set('services.telegram.primary_chat_id', null);
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.message_locale', 'ru');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $user = TelegramUser::query()->create([
            'telegram_user_id' => 321654,
            'username' => 'alex',
            'first_name' => 'Alex',
        ]);

        $reminder = Reminder::query()->create([
            'message' => 'Water flowers',
            'user_id' => $user->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'timezone' => 'UTC',
            'next_run_at' => now()->addHours(3),
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 93006,
                'callback_query' => [
                    'id' => 'my-reminders-toggle',
                    'from' => [
                        'id' => 321654,
                        'is_bot' => false,
                        'first_name' => 'Alex',
                        'username' => 'alex',
                        'language_code' => 'ru',
                    ],
                    'message' => [
                        'message_id' => 904,
                        'chat' => [
                            'id' => -1001234567890,
                            'type' => 'supergroup',
                            'title' => 'Family',
                        ],
                    ],
                    'data' => sprintf('myreminders:toggle:321654:%d', $reminder->id),
                ],
            ])
            ->assertOk();

        $this->assertSame(Reminder::STATUS_PAUSED, $reminder->refresh()->status);

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) use ($reminder): bool {
            $data = $request->data();
            $text = (string) ($data['text'] ?? '');

            return str_contains($request->url(), '/editMessageText')
                && str_contains($text, '🟡 <b>Пауза</b>')
                && data_get($data, 'reply_markup.inline_keyboard.0.0.text') === 'Возобновить'
                && data_get($data, 'reply_markup.inline_keyboard.0.0.callback_data') === sprintf('myreminders:toggle:321654:%d', $reminder->id);
        });
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/answerCallbackQuery')
                && $data['callback_query_id'] === 'my-reminders-toggle'
                && $data['text'] === 'Напоминание поставлено на паузу.';
        });
    }

    public function test_it_opens_delete_confirmation_from_card(): void
    {
        config()->set('services.telegram.webhook_secret', 'test-secret');
        config()->set('services.telegram.primary_chat_id', null);
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.message_locale', 'ru');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $user = TelegramUser::query()->create([
            'telegram_user_id' => 321654,
            'username' => 'alex',
            'first_name' => 'Alex',
        ]);

        $reminder = Reminder::query()->create([
            'message' => 'Pay internet',
            'user_id' => $user->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'timezone' => 'UTC',
            'next_run_at' => now()->addDays(1),
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 93007,
                'callback_query' => [
                    'id' => 'my-reminders-delete',
                    'from' => [
                        'id' => 321654,
                        'is_bot' => false,
                        'first_name' => 'Alex',
                        'username' => 'alex',
                        'language_code' => 'ru',
                    ],
                    'message' => [
                        'message_id' => 905,
                        'chat' => [
                            'id' => -1001234567890,
                            'type' => 'supergroup',
                            'title' => 'Family',
                        ],
                    ],
                    'data' => sprintf('myreminders:delete:321654:%d', $reminder->id),
                ],
            ])
            ->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) use ($reminder): bool {
            $data = $request->data();
            $text = (string) ($data['text'] ?? '');

            return str_contains($request->url(), '/editMessageText')
                && str_contains($text, 'Удалить это напоминание?')
                && str_contains($text, 'Pay internet')
                && data_get($data, 'reply_markup.inline_keyboard.0.0.text') === 'Подтвердить удаление'
                && data_get($data, 'reply_markup.inline_keyboard.0.0.callback_data') === sprintf('myreminders:delete_confirm:321654:%d', $reminder->id)
                && data_get($data, 'reply_markup.inline_keyboard.1.0.text') === 'Отмена удаления'
                && data_get($data, 'reply_markup.inline_keyboard.1.0.callback_data') === sprintf('myreminders:delete_cancel:321654:%d', $reminder->id);
        });
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/answerCallbackQuery')
                && $data['callback_query_id'] === 'my-reminders-delete'
                && $data['text'] === 'Подтверди удаление.';
        });
    }

    public function test_it_deletes_reminder_after_delete_confirmation(): void
    {
        config()->set('services.telegram.webhook_secret', 'test-secret');
        config()->set('services.telegram.primary_chat_id', null);
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.message_locale', 'ru');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $user = TelegramUser::query()->create([
            'telegram_user_id' => 321654,
            'username' => 'alex',
            'first_name' => 'Alex',
        ]);

        $reminder = Reminder::query()->create([
            'message' => 'Pay rent',
            'user_id' => $user->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'timezone' => 'UTC',
            'next_run_at' => now()->addDays(2),
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 93008,
                'callback_query' => [
                    'id' => 'my-reminders-delete-confirm',
                    'from' => [
                        'id' => 321654,
                        'is_bot' => false,
                        'first_name' => 'Alex',
                        'username' => 'alex',
                        'language_code' => 'ru',
                    ],
                    'message' => [
                        'message_id' => 906,
                        'chat' => [
                            'id' => -1001234567890,
                            'type' => 'supergroup',
                            'title' => 'Family',
                        ],
                    ],
                    'data' => sprintf('myreminders:delete_confirm:321654:%d', $reminder->id),
                ],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('reminders', [
            'id' => $reminder->id,
        ]);

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();
            $text = (string) ($data['text'] ?? '');

            return str_contains($request->url(), '/editMessageText')
                && str_contains($text, 'У тебя нет будущих напоминаний.')
                && data_get($data, 'reply_markup.inline_keyboard.0.0.text') === 'Закрыть'
                && data_get($data, 'reply_markup.inline_keyboard.0.0.callback_data') === 'myreminders:close:321654';
        });
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/answerCallbackQuery')
                && $data['callback_query_id'] === 'my-reminders-delete-confirm'
                && $data['text'] === 'Напоминание удалено.';
        });
    }
}
