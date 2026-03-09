<?php

namespace App\Services\Weather;

use App\Models\WeatherLocation;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

class OpenMeteoClient
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {
    }

    public function forecast(WeatherLocation $location, array $query = []): array
    {
        $defaultQuery = [
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'timezone' => $location->timezoneOrDefault(),
            'forecast_days' => 1,
            'current' => 'temperature_2m,weather_code,precipitation,wind_speed_10m',
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
        ];

        return $this->request('v1/forecast', array_merge($defaultQuery, $query));
    }

    public function request(string $path, array $query = []): array
    {
        $response = $this->client()->get(ltrim($path, '/'), $query);
        $response->throw();

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Open-Meteo returned unexpected payload.');
        }

        return [
            'http_status' => $response->status(),
            'payload' => $payload,
        ];
    }

    private function client(): PendingRequest
    {
        $apiUrl = rtrim((string) config('services.open_meteo.api_url', 'https://api.open-meteo.com'), '/');
        $timeout = max(1, (int) config('services.open_meteo.timeout', 10));

        return $this->http
            ->acceptJson()
            ->timeout($timeout)
            ->retry(2, 200, throw: false)
            ->baseUrl($apiUrl);
    }
}
