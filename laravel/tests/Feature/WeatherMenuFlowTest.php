<?php

namespace Tests\Feature;

use App\Models\WeatherLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WeatherMenuFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_handles_weather_menu_flow_with_current_weather_and_cleanup(): void
    {
        config()->set('services.telegram.webhook_secret', 'test-secret');
        config()->set('services.telegram.primary_chat_id', null);
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.message_locale', 'ru');
        config()->set('services.open_meteo.api_url', 'https://api.open-meteo.com');

        $berlin = WeatherLocation::query()->create([
            'name' => 'Berlin',
            'latitude' => 52.52,
            'longitude' => 13.405,
            'timezone' => 'Europe/Berlin',
            'is_active' => true,
        ]);

        $kyiv = WeatherLocation::query()->create([
            'name' => 'Kyiv',
            'latitude' => 50.4501,
            'longitude' => 30.5234,
            'timezone' => 'Europe/Kyiv',
            'is_active' => false,
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.open-meteo.com/v1/forecast')) {
                return Http::response([
                    'current' => [
                        'time' => '2026-03-08T12:00',
                        'temperature_2m' => 12.4,
                        'apparent_temperature' => 10.1,
                        'weather_code' => 3,
                        'wind_speed_10m' => 5.3,
                        'wind_direction_10m' => 90.0,
                    ],
                    'daily' => [
                        'time' => ['2026-03-08'],
                        'sunrise' => ['2026-03-08T06:20'],
                        'sunset' => ['2026-03-08T17:48'],
                    ],
                ], 200);
            }

            if (str_contains($request->url(), '/sendMessage')) {
                static $messageId = 1000;
                $messageId++;

                return Http::response([
                    'ok' => true,
                    'result' => [
                        'message_id' => $messageId,
                    ],
                ], 200);
            }

            return Http::response([
                'ok' => true,
                'result' => true,
            ], 200);
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
                'update_id' => 95001,
                'message' => [
                    'message_id' => 51,
                    'date' => now()->timestamp,
                    'chat' => $chatPayload,
                    'from' => $userPayload,
                    'text' => '🌤 Погода',
                ],
            ])
            ->assertOk();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 95002,
                'callback_query' => [
                    'id' => 'weather-pick',
                    'from' => $userPayload,
                    'message' => [
                        'message_id' => 1001,
                        'chat' => $chatPayload,
                    ],
                    'data' => sprintf('weather:pick:321654:%d:51', $kyiv->id),
                ],
            ])
            ->assertOk();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 95003,
                'callback_query' => [
                    'id' => 'weather-close',
                    'from' => $userPayload,
                    'message' => [
                        'message_id' => 1002,
                        'chat' => $chatPayload,
                    ],
                    'data' => 'weather:close:321654',
                ],
            ])
            ->assertOk();

        Http::assertSent(function (Request $request) use ($berlin, $kyiv): bool {
            if (! str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            $data = $request->data();
            $text = (string) ($data['text'] ?? '');

            return $text === 'Выбери локацию для прогноза:'
                && ($data['disable_notification'] ?? false) === true
                && data_get($data, 'reply_markup.inline_keyboard.0.0.text') === '🟢 '.$berlin->name
                && data_get($data, 'reply_markup.inline_keyboard.0.0.callback_data') === sprintf('weather:pick:321654:%d:51', $berlin->id)
                && data_get($data, 'reply_markup.inline_keyboard.1.0.text') === '⚪️ '.$kyiv->name
                && data_get($data, 'reply_markup.inline_keyboard.1.0.callback_data') === sprintf('weather:pick:321654:%d:51', $kyiv->id)
                && data_get($data, 'reply_markup.inline_keyboard.2.0.text') === 'Закрыть'
                && data_get($data, 'reply_markup.inline_keyboard.2.0.callback_data') === 'weather:close:321654:51';
        });

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'api.open-meteo.com/v1/forecast')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['timezone'] ?? null) === 'Europe/Kyiv'
                && ($query['forecast_days'] ?? null) === '1'
                && ($query['current'] ?? null) === 'temperature_2m,apparent_temperature,weather_code,wind_speed_10m,wind_direction_10m'
                && ($query['daily'] ?? null) === 'sunrise,sunset';
        });

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            $data = $request->data();
            $text = (string) ($data['text'] ?? '');

            return str_contains($text, 'Погода: Kyiv')
                && str_contains($text, '<b>Сейчас:</b>')
                && ! str_contains($text, '<b>Ближайшие 2 часа:</b>')
                && ! str_contains($text, '<b>За день:</b>')
                && str_contains($text, '• 🌅 восход: 06:20')
                && str_contains($text, '• 🌇 закат: 17:48')
                && ($data['disable_notification'] ?? false) === true
                && ($data['parse_mode'] ?? null) === 'HTML'
                && data_get($data, 'reply_markup.inline_keyboard.0.0.text') === 'Закрыть'
                && data_get($data, 'reply_markup.inline_keyboard.0.0.callback_data') === 'weather:close:321654';
        });

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/deleteMessage')) {
                return false;
            }

            $data = $request->data();

            return (int) $data['chat_id'] === -1001234567890
                && (int) $data['message_id'] === 1001;
        });

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/deleteMessage')) {
                return false;
            }

            $data = $request->data();

            return (int) $data['chat_id'] === -1001234567890
                && (int) $data['message_id'] === 51;
        });

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/deleteMessage')) {
                return false;
            }

            $data = $request->data();

            return (int) $data['chat_id'] === -1001234567890
                && (int) $data['message_id'] === 1002;
        });

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/answerCallbackQuery')) {
                return false;
            }

            $data = $request->data();

            return $data['callback_query_id'] === 'weather-pick'
                && $data['text'] === 'Погода отправлена.';
        });

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/answerCallbackQuery')) {
                return false;
            }

            $data = $request->data();

            return $data['callback_query_id'] === 'weather-close'
                && $data['text'] === 'Закрыто.';
        });
    }
}
