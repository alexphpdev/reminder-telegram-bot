<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\ReminderDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomReminderDraftFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_one_time_reminder_in_group_flow_and_cleans_up_draft_messages(): void
    {
        config()->set('services.telegram.webhook_secret', 'test-secret');
        config()->set('services.telegram.primary_chat_id', null);
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.message_locale', 'ru');

        $startedAt = Carbon::create(2026, 3, 8, 10, 0, 0, 'Europe/Berlin');
        $this->travelTo($startedAt);

        Http::fake(function (Request $request) {
            static $messageId = 700;

            if (str_contains($request->url(), '/sendMessage')) {
                $messageId++;

                return Http::response([
                    'ok' => true,
                    'result' => [
                        'message_id' => $messageId,
                    ],
                ]);
            }

            return Http::response([
                'ok' => true,
                'result' => true,
            ]);
        });

        $chatPayload = [
            'id' => -1001234567890,
            'type' => 'supergroup',
            'title' => 'Family',
        ];

        $userPayload = [
            'id' => 321654,
            'is_bot' => false,
            'first_name' => 'Alex',
            'username' => 'alex',
            'language_code' => 'ru',
        ];

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 91001,
                'message' => [
                    'message_id' => 11,
                    'date' => $startedAt->timestamp,
                    'chat' => $chatPayload,
                    'from' => $userPayload,
                    'text' => '➕ Добавить напоминание',
                ],
            ])
            ->assertOk();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 91002,
                'message' => [
                    'message_id' => 12,
                    'date' => $startedAt->timestamp,
                    'chat' => $chatPayload,
                    'from' => $userPayload,
                    'text' => 'Купить молоко',
                ],
            ])
            ->assertOk();

        $draft = ReminderDraft::query()->firstOrFail();

        $this->assertSame(ReminderDraft::STEP_AWAITING_DAY, $draft->step);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 91003,
                'callback_query' => [
                    'id' => 'draft-day',
                    'from' => $userPayload,
                    'message' => [
                        'message_id' => 702,
                        'chat' => $chatPayload,
                    ],
                    'data' => sprintf('draft:%d:day:2026-03-09', $draft->id),
                ],
            ])
            ->assertOk();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 91004,
                'message' => [
                    'message_id' => 13,
                    'date' => $startedAt->timestamp,
                    'chat' => $chatPayload,
                    'from' => $userPayload,
                    'text' => '08:30',
                ],
            ])
            ->assertOk();

        $draft->refresh();
        $this->assertSame(ReminderDraft::STEP_AWAITING_STATUS_MODE, $draft->step);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 910045,
                'callback_query' => [
                    'id' => 'draft-mode',
                    'from' => $userPayload,
                    'message' => [
                        'message_id' => 704,
                        'chat' => $chatPayload,
                    ],
                    'data' => sprintf('draft:%d:mode:ask', $draft->id),
                ],
            ])
            ->assertOk();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 91005,
                'callback_query' => [
                    'id' => 'draft-confirm',
                    'from' => $userPayload,
                    'message' => [
                        'message_id' => 705,
                        'chat' => $chatPayload,
                    ],
                    'data' => sprintf('draft:%d:confirm', $draft->id),
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('reminders', [
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'message' => 'Купить молоко',
            'status' => Reminder::STATUS_ACTIVE,
            'ask_status' => 1,
        ]);

        $draft->refresh();

        $this->assertSame(ReminderDraft::STATUS_COMPLETED, $draft->status);
        $this->assertNotNull($draft->completed_at);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();
            $text = (string) ($data['text'] ?? '');

            return str_contains($request->url(), '/sendMessage')
                && ($data['disable_notification'] ?? false) === true
                && str_contains($text, 'Создано одноразовое напоминание')
                && str_contains($text, 'Кому: <a href="tg://user?id=321654">Alex</a>');
        });

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/deleteMessage');
        });
    }

    public function test_it_cancels_active_draft_and_deletes_related_messages(): void
    {
        config()->set('services.telegram.webhook_secret', 'test-secret');
        config()->set('services.telegram.primary_chat_id', null);
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.message_locale', 'ru');

        Http::fake(function (Request $request) {
            static $messageId = 800;

            if (str_contains($request->url(), '/sendMessage')) {
                $messageId++;

                return Http::response([
                    'ok' => true,
                    'result' => [
                        'message_id' => $messageId,
                    ],
                ]);
            }

            return Http::response([
                'ok' => true,
                'result' => true,
            ]);
        });

        $chatPayload = [
            'id' => -1001234567890,
            'type' => 'supergroup',
            'title' => 'Family',
        ];

        $userPayload = [
            'id' => 321654,
            'is_bot' => false,
            'first_name' => 'Alex',
            'username' => 'alex',
            'language_code' => 'ru',
        ];

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 92001,
                'message' => [
                    'message_id' => 21,
                    'date' => now()->timestamp,
                    'chat' => $chatPayload,
                    'from' => $userPayload,
                    'text' => '➕ Добавить напоминание',
                ],
            ])
            ->assertOk();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 92002,
                'message' => [
                    'message_id' => 22,
                    'date' => now()->timestamp,
                    'chat' => $chatPayload,
                    'from' => $userPayload,
                    'text' => '/cancel',
                ],
            ])
            ->assertOk();

        $draft = ReminderDraft::query()->firstOrFail();

        $this->assertSame(ReminderDraft::STATUS_CANCELED, $draft->status);
        $this->assertDatabaseCount('reminders', 0);

        Http::assertNotSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/sendMessage')
                && str_contains((string) ($data['text'] ?? ''), 'Создание напоминания отменено.');
        });
        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/deleteMessage');
        });
    }

    public function test_it_creates_one_time_reminder_in_notify_only_mode_without_status_buttons(): void
    {
        config()->set('services.telegram.webhook_secret', 'test-secret');
        config()->set('services.telegram.primary_chat_id', null);
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.message_locale', 'ru');

        $startedAt = Carbon::create(2026, 3, 8, 10, 0, 0, 'Europe/Berlin');
        $this->travelTo($startedAt);

        Http::fake(function (Request $request) {
            static $messageId = 1200;

            if (str_contains($request->url(), '/sendMessage')) {
                $messageId++;

                return Http::response([
                    'ok' => true,
                    'result' => [
                        'message_id' => $messageId,
                    ],
                ]);
            }

            return Http::response([
                'ok' => true,
                'result' => true,
            ]);
        });

        $chatPayload = [
            'id' => -1001234567890,
            'type' => 'supergroup',
            'title' => 'Family',
        ];

        $userPayload = [
            'id' => 321654,
            'is_bot' => false,
            'first_name' => 'Alex',
            'username' => 'alex',
            'language_code' => 'ru',
        ];

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 93001,
                'message' => [
                    'message_id' => 31,
                    'date' => $startedAt->timestamp,
                    'chat' => $chatPayload,
                    'from' => $userPayload,
                    'text' => '➕ Добавить напоминание',
                ],
            ])
            ->assertOk();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 93002,
                'message' => [
                    'message_id' => 32,
                    'date' => $startedAt->timestamp,
                    'chat' => $chatPayload,
                    'from' => $userPayload,
                    'text' => 'Проверить почту',
                ],
            ])
            ->assertOk();

        $draft = ReminderDraft::query()->firstOrFail();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 93003,
                'callback_query' => [
                    'id' => 'draft-day-notify',
                    'from' => $userPayload,
                    'message' => [
                        'message_id' => 1202,
                        'chat' => $chatPayload,
                    ],
                    'data' => sprintf('draft:%d:day:2026-03-09', $draft->id),
                ],
            ])
            ->assertOk();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 93004,
                'message' => [
                    'message_id' => 33,
                    'date' => $startedAt->timestamp,
                    'chat' => $chatPayload,
                    'from' => $userPayload,
                    'text' => '09:00',
                ],
            ])
            ->assertOk();

        $draft->refresh();
        $this->assertSame(ReminderDraft::STEP_AWAITING_STATUS_MODE, $draft->step);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 93005,
                'callback_query' => [
                    'id' => 'draft-mode-notify',
                    'from' => $userPayload,
                    'message' => [
                        'message_id' => 1204,
                        'chat' => $chatPayload,
                    ],
                    'data' => sprintf('draft:%d:mode:notify', $draft->id),
                ],
            ])
            ->assertOk();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 93006,
                'callback_query' => [
                    'id' => 'draft-confirm-notify',
                    'from' => $userPayload,
                    'message' => [
                        'message_id' => 1205,
                        'chat' => $chatPayload,
                    ],
                    'data' => sprintf('draft:%d:confirm', $draft->id),
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('reminders', [
            'message' => 'Проверить почту',
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'ask_status' => 0,
        ]);
    }
}
