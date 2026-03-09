<?php

namespace App\Services\Weather;

use App\Models\WeatherLocation;
use App\Models\WeatherHistory;
use Throwable;

class WeatherCollector
{
    public function __construct(
        private readonly OpenMeteoClient $openMeteoClient,
    ) {
    }

    /**
     * @return array{processed:int,successful:int,failed:int}
     */
    public function collectActiveLocations(): array
    {
        $processed = 0;
        $successful = 0;
        $failed = 0;

        $locations = WeatherLocation::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($locations as $location) {
            $processed++;

            try {
                $apiResponse = $this->openMeteoClient->forecast($location);

                WeatherHistory::query()->create([
                    'weather_location_id' => $location->id,
                    'provider' => 'open-meteo',
                    'response_status' => WeatherHistory::STATUS_SUCCESS,
                    'http_status' => (int) ($apiResponse['http_status'] ?? 200),
                    'requested_at' => now(),
                    'response_payload' => $apiResponse['payload'] ?? null,
                    'error_message' => null,
                ]);

                $successful++;
            } catch (Throwable $exception) {
                report($exception);

                WeatherHistory::query()->create([
                    'weather_location_id' => $location->id,
                    'provider' => 'open-meteo',
                    'response_status' => WeatherHistory::STATUS_FAILED,
                    'http_status' => null,
                    'requested_at' => now(),
                    'response_payload' => null,
                    'error_message' => $exception->getMessage(),
                ]);

                $failed++;
            }
        }

        return [
            'processed' => $processed,
            'successful' => $successful,
            'failed' => $failed,
        ];
    }
}
