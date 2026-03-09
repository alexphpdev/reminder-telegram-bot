<?php

namespace Tests\Feature;

use App\Models\WeatherHistory;
use App\Models\WeatherLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WeatherDeleteHistoryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_only_rows_older_than_given_days(): void
    {
        $location = WeatherLocation::query()->create([
            'name' => 'Berlin',
            'latitude' => 52.52,
            'longitude' => 13.405,
            'timezone' => 'Europe/Berlin',
            'is_active' => true,
        ]);

        WeatherHistory::query()->create([
            'weather_location_id' => $location->id,
            'provider' => 'open-meteo',
            'response_status' => WeatherHistory::STATUS_SUCCESS,
            'http_status' => 200,
            'requested_at' => Carbon::now()->subDays(10),
            'response_payload' => ['ok' => true],
            'error_message' => null,
        ]);

        WeatherHistory::query()->create([
            'weather_location_id' => $location->id,
            'provider' => 'open-meteo',
            'response_status' => WeatherHistory::STATUS_SUCCESS,
            'http_status' => 200,
            'requested_at' => Carbon::now()->subDay(),
            'response_payload' => ['ok' => true],
            'error_message' => null,
        ]);

        $this->artisan('weather:delete-history', ['--days' => 7])
            ->assertSuccessful();

        $this->assertDatabaseCount('weather_history', 1);
    }

    public function test_it_requires_all_or_days_option(): void
    {
        $this->artisan('weather:delete-history')
            ->assertFailed();
    }
}
