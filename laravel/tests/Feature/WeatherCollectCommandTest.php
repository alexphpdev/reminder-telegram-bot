<?php

namespace Tests\Feature;

use App\Models\WeatherLocation;
use App\Models\WeatherHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WeatherCollectCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_collects_weather_only_for_active_locations_and_stores_response(): void
    {
        config()->set('services.open_meteo.api_url', 'https://api.open-meteo.com');
        config()->set('services.open_meteo.timeout', 10);

        Http::fake([
            'https://api.open-meteo.com/*' => Http::response([
                'latitude' => 52.5200,
                'longitude' => 13.4050,
                'timezone' => 'Europe/Berlin',
                'current' => [
                    'temperature_2m' => 9.8,
                    'weather_code' => 3,
                ],
                'daily' => [
                    'temperature_2m_min' => [4.2],
                    'temperature_2m_max' => [12.6],
                ],
            ], 200),
        ]);

        $activeLocation = WeatherLocation::query()->create([
            'name' => 'Berlin',
            'latitude' => 52.5200,
            'longitude' => 13.4050,
            'timezone' => 'Europe/Berlin',
            'is_active' => true,
        ]);

        WeatherLocation::query()->create([
            'name' => 'Paris',
            'latitude' => 48.8566,
            'longitude' => 2.3522,
            'timezone' => 'Europe/Paris',
            'is_active' => false,
        ]);

        $this->artisan('weather:collect')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $query = $request->data();

            return str_contains($request->url(), '/v1/forecast')
                && (float) $query['latitude'] === 52.52
                && (float) $query['longitude'] === 13.405
                && $query['timezone'] === 'Europe/Berlin'
                && $query['forecast_days'] === 1;
        });

        $this->assertDatabaseCount('weather_history', 1);
        $this->assertDatabaseHas('weather_history', [
            'weather_location_id' => $activeLocation->id,
            'provider' => 'open-meteo',
            'response_status' => WeatherHistory::STATUS_SUCCESS,
            'http_status' => 200,
        ]);
    }
}
