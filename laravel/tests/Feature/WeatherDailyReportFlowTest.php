<?php

namespace Tests\Feature;

use App\Models\TelegramChat;
use App\Models\WeatherHistory;
use App\Models\WeatherLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WeatherDailyReportFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_collects_before_report_window_and_sends_daily_report_once(): void
    {
        config()->set('app.timezone', 'UTC');
        config()->set('services.open_meteo.api_url', 'https://api.open-meteo.com');
        config()->set('services.open_meteo.timeout', 10);
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.primary_chat_id', null);
        config()->set('services.telegram.message_locale', 'ru');
        config()->set('weather.daily_report.time', '07:00');
        config()->set('weather.daily_report.disable_notification', false);

        TelegramChat::query()->create([
            'telegram_chat_id' => -1001234567890,
            'type' => 'supergroup',
            'title' => 'Family',
            'is_primary' => true,
        ]);

        WeatherLocation::query()->create([
            'name' => 'Berlin',
            'latitude' => 52.52,
            'longitude' => 13.405,
            'timezone' => 'Europe/Berlin',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.open-meteo.com/*' => Http::response([
                'current' => [
                    'time' => '2026-03-08T07:00',
                    'temperature_2m' => 6.4,
                    'apparent_temperature' => 3.9,
                    'weather_code' => 3,
                    'wind_speed_10m' => 12.2,
                    'wind_direction_10m' => 90.0,
                ],
                'hourly' => [
                    'time' => [
                        '2026-03-08T07:00',
                        '2026-03-08T08:00',
                        '2026-03-08T20:00',
                        '2026-03-08T23:00',
                        '2026-03-09T00:00',
                        '2026-03-09T06:00',
                    ],
                    'temperature_2m' => [6.4, 7.8, 10.0, 5.0, 4.0, 3.0],
                    'apparent_temperature' => [3.9, 5.2, 7.0, 2.0, 1.0, 0.0],
                    'weather_code' => [3, 2, 2, 3, 3, 1],
                    'wind_speed_10m' => [12.2, 14.1, 11.0, 9.0, 8.0, 7.0],
                    'precipitation_probability' => [10, 40, 20, 30, 35, 45],
                ],
                'daily' => [
                    'time' => ['2026-03-08'],
                    'weather_code' => [3],
                    'temperature_2m_min' => [2.3],
                    'temperature_2m_max' => [10.8],
                    'precipitation_probability_max' => [40],
                    'sunrise' => ['2026-03-08T06:41'],
                    'sunset' => ['2026-03-08T18:09'],
                ],
            ], 200),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9001,
                ],
            ], 200),
        ]);

        $this->travelTo(Carbon::create(2026, 3, 8, 5, 40, 0, 'UTC'));

        $this->artisan('weather:collect-daily-snapshots')
            ->assertSuccessful();

        $this->assertDatabaseCount('weather_history', 0);

        $this->travelTo(Carbon::create(2026, 3, 8, 5, 55, 0, 'UTC'));

        $this->artisan('weather:collect-daily-snapshots')
            ->assertSuccessful();

        $this->assertDatabaseCount('weather_history', 1);
        $this->assertDatabaseHas('weather_history', [
            'weather_location_id' => 1,
            'response_status' => WeatherHistory::STATUS_SUCCESS,
            'http_status' => 200,
        ]);

        $this->travelTo(Carbon::create(2026, 3, 8, 5, 56, 0, 'UTC'));

        $this->artisan('weather:collect-daily-snapshots')
            ->assertSuccessful();

        $this->assertDatabaseCount('weather_history', 1);

        $this->travelTo(Carbon::create(2026, 3, 8, 5, 57, 0, 'UTC'));

        $this->artisan('weather:collect-daily-snapshots')
            ->assertSuccessful();

        $this->assertDatabaseCount('weather_history', 1);

        $this->travelTo(Carbon::create(2026, 3, 8, 6, 0, 0, 'UTC'));

        $this->artisan('weather:send-daily-reports')
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            $data = $request->data();
            $text = (string) ($data['text'] ?? '');

            return (int) $data['chat_id'] === -1001234567890
                && str_contains($text, '☁️ Погода: Berlin')
                && ($data['parse_mode'] ?? null) === 'HTML'
                && str_contains($text, '<b>Сейчас:</b>')
                && str_contains($text, '• 🌡️ температура: +6°C')
                && str_contains($text, '• 🥶 ощущается как: +4°C')
                && str_contains($text, '• ☁️ обстановка: Пасмурно')
                && str_contains($text, '• 🧭💨 направление ветра: ➡️')
                && str_contains($text, '<b>Ближайшие 2 часа:</b>')
                && str_contains($text, '• 🌡️ температура: +6°C…+8°C')
                && str_contains($text, '• вероятность осадков: до 40%')
                && str_contains($text, '• ☁️ обстановка: Пасмурно')
                && str_contains($text, '<b>За день:</b>')
                && str_contains($text, '• ☀️ дневная🌡️: +6°C…+10°C')
                && str_contains($text, '• 🌙 ночная🌡️: +3°C…+5°C')
                && str_contains($text, '• 🌅 восход: 06:41')
                && str_contains($text, '• 🌇 закат: 18:09');
        });

        $this->travelTo(Carbon::create(2026, 3, 8, 6, 1, 0, 'UTC'));

        $this->artisan('weather:send-daily-reports')
            ->assertSuccessful();

        Http::assertSentCount(2);
    }

    public function test_it_sends_unavailable_message_when_no_snapshot_exists_for_report_window(): void
    {
        config()->set('app.timezone', 'UTC');
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.primary_chat_id', null);
        config()->set('services.telegram.message_locale', 'ru');
        config()->set('weather.daily_report.time', '07:00');
        config()->set('weather.daily_report.disable_notification', false);

        TelegramChat::query()->create([
            'telegram_chat_id' => -1001234567890,
            'type' => 'supergroup',
            'title' => 'Family',
            'is_primary' => true,
        ]);

        WeatherLocation::query()->create([
            'name' => 'Berlin',
            'latitude' => 52.52,
            'longitude' => 13.405,
            'timezone' => 'Europe/Berlin',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9002,
                ],
            ], 200),
        ]);

        $this->travelTo(Carbon::create(2026, 3, 8, 6, 0, 0, 'UTC'));

        $this->artisan('weather:send-daily-reports')
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            $text = (string) ($request->data()['text'] ?? '');

            return str_contains($text, '🌤 Погода: Berlin')
                && str_contains($text, 'Данные о погоде недоступны.');
        });
    }
}
