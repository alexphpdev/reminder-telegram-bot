<?php

namespace Tests\Feature;

use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramPollUpdatesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_polls_updates_from_telegram_and_processes_them(): void
    {
        config()->set('services.telegram.bot_token', 'test-token');

        TelegramUpdate::query()->create([
            'update_id' => 100,
            'update_type' => 'message',
            'payload' => ['update_id' => 100],
            'received_at' => now(),
        ]);

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [[
                    'update_id' => 101,
                    'message' => [
                        'message_id' => 10,
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
                        'text' => 'hello from polling',
                    ],
                ]],
            ]),
        ]);

        $this->artisan('telegram:poll-updates --once --timeout=0')
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/getUpdates')
                && (int) $data['offset'] === 101
                && (int) $data['timeout'] === 0;
        });

        $this->assertDatabaseHas('telegram_updates', [
            'update_id' => 101,
            'update_type' => 'message',
            'message_text' => 'hello from polling',
        ]);

        $this->assertDatabaseHas('telegram_chats', [
            'telegram_chat_id' => -1001234567890,
            'title' => 'Family',
        ]);

        $this->assertDatabaseHas('telegram_users', [
            'telegram_user_id' => 321654,
            'username' => 'alex',
        ]);
    }
}
