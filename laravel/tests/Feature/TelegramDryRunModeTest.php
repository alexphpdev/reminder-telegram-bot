<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\TelegramChat;
use App\Models\TelegramUpdate;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramDryRunModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_logs_due_reminder_dispatch_in_dry_run_mode_without_calling_telegram_api(): void
    {
        $logPath = storage_path('logs/telegram-dev/test-'.Str::uuid().'.log');

        config()->set('services.telegram.disable_send_to_telegram', true);
        config()->set('logging.channels.telegram-dev.path', $logPath);
        app('log')->forgetChannel('telegram-dev');

        Http::fake();

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
            'message' => 'Сухой прогон уведомления.',
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_INTERVAL,
            'interval_minutes' => 60,
            'timezone' => 'Europe/Berlin',
            'next_run_at' => now()->subMinute(),
            'ask_status' => true,
        ]);

        $this->artisan('reminders:dispatch-due')->assertSuccessful();

        Http::assertNothingSent();

        $reminder->refresh();

        $this->assertNotNull($reminder->last_sent_at);
        $this->assertNotNull($reminder->next_run_at);

        $delivery = ReminderDelivery::query()->sole();
        $logFiles = glob(str_replace('.log', '-*.log', $logPath));

        $this->assertSame(ReminderDelivery::STATUS_SENT, $delivery->delivery_status);
        $this->assertNotNull($delivery->telegram_message_id);
        $this->assertTrue((bool) data_get($delivery->response_payload, 'dry_run'));
        $this->assertIsArray($logFiles);
        $this->assertCount(1, $logFiles);
        $this->assertStringContainsString('Сухой прогон уведомления.', file_get_contents($logFiles[0]));
        $this->assertStringContainsString('sendMessage', file_get_contents($logFiles[0]));
    }

    public function test_it_keeps_get_updates_enabled_when_send_is_disabled(): void
    {
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.disable_send_to_telegram', true);

        TelegramUpdate::query()->create([
            'update_id' => 100,
            'update_type' => 'message',
            'payload' => ['update_id' => 100],
            'received_at' => now(),
        ]);

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [],
            ]),
        ]);

        $response = app(TelegramBotClient::class)->getUpdates(offset: 101, timeout: 0);

        $this->assertTrue($response['ok']);
        $this->assertSame([], $response['result']);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/getUpdates')
                && (int) $data['offset'] === 101
                && (int) $data['timeout'] === 0;
        });
    }
}
