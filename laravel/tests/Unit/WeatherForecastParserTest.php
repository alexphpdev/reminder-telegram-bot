<?php

namespace Tests\Unit;

use App\Services\Weather\WeatherForecastParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class WeatherForecastParserTest extends TestCase
{
    public function test_it_parses_current_next_two_hours_and_daily_sections(): void
    {
        $parser = new WeatherForecastParser();

        $payload = [
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
                    '2026-03-08T09:00',
                    '2026-03-08T20:00',
                    '2026-03-08T23:00',
                    '2026-03-09T00:00',
                    '2026-03-09T06:00',
                ],
                'temperature_2m' => [6.4, 7.8, 8.1, 10.0, 5.0, 4.0, 3.0],
                'apparent_temperature' => [3.9, 5.2, 5.6, 7.0, 2.0, 1.0, 0.0],
                'weather_code' => [3, 2, 2, 2, 3, 3, 1],
                'wind_speed_10m' => [12.2, 14.1, 15.0, 11.0, 9.0, 8.0, 7.0],
                'precipitation_probability' => [10, 40, 50, 20, 30, 35, 45],
            ],
            'daily' => [
                'time' => ['2026-03-08', '2026-03-09'],
                'weather_code' => [3, 2],
                'temperature_2m_min' => [2.3, 1.0],
                'temperature_2m_max' => [10.8, 9.0],
                'precipitation_probability_max' => [40, 20],
                'sunrise' => ['2026-03-08T06:41', '2026-03-09T06:39'],
                'sunset' => ['2026-03-08T18:09', '2026-03-09T18:11'],
            ],
        ];

        $parsed = $parser->parseDailyReportPayload(
            $payload,
            'Europe/Berlin',
            CarbonImmutable::parse('2026-03-08T07:00:00', 'Europe/Berlin'),
        );

        $this->assertSame(6.4, $parsed['current']['temperature_2m']);
        $this->assertSame(3.9, $parsed['current']['apparent_temperature']);
        $this->assertSame(3, $parsed['current']['weather_code']);
        $this->assertSame(12.2, $parsed['current']['wind_speed_10m']);
        $this->assertSame(90.0, $parsed['current']['wind_direction_10m']);

        $this->assertSame(2, $parsed['next_two_hours']['points']);
        $this->assertSame(6.4, $parsed['next_two_hours']['temperature_min']);
        $this->assertSame(7.8, $parsed['next_two_hours']['temperature_max']);
        $this->assertSame(3.9, $parsed['next_two_hours']['apparent_temperature_min']);
        $this->assertSame(5.2, $parsed['next_two_hours']['apparent_temperature_max']);
        $this->assertSame(12.2, $parsed['next_two_hours']['wind_speed_min']);
        $this->assertSame(14.1, $parsed['next_two_hours']['wind_speed_max']);
        $this->assertSame(40.0, $parsed['next_two_hours']['precipitation_probability_max']);
        $this->assertSame([3, 2], $parsed['next_two_hours']['weather_codes']);
        $this->assertSame(3, $parsed['next_two_hours']['weather_code']);

        $this->assertSame(3, $parsed['daily']['weather_code']);
        $this->assertSame(2.3, $parsed['daily']['temperature_2m_min']);
        $this->assertSame(10.8, $parsed['daily']['temperature_2m_max']);
        $this->assertSame(6.4, $parsed['daily']['daytime_temperature_min']);
        $this->assertSame(10.0, $parsed['daily']['daytime_temperature_max']);
        $this->assertSame(3.0, $parsed['daily']['nighttime_temperature_min']);
        $this->assertSame(5.0, $parsed['daily']['nighttime_temperature_max']);
        $this->assertSame(40.0, $parsed['daily']['precipitation_probability_max']);
        $this->assertSame('06:41', $parsed['daily']['sunrise']?->format('H:i'));
        $this->assertSame('18:09', $parsed['daily']['sunset']?->format('H:i'));
    }
}
