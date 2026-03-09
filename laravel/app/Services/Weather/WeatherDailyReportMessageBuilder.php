<?php

namespace App\Services\Weather;

use App\Models\WeatherLocation;
use Carbon\CarbonImmutable;

class WeatherDailyReportMessageBuilder
{
    /**
     * @param array{
     *     current:array{time:CarbonImmutable|null,temperature_2m:float|null,apparent_temperature:float|null,weather_code:int|null,wind_speed_10m:float|null,wind_direction_10m:float|null},
     *     daily:array{
     *         sunrise:CarbonImmutable|null,
     *         sunset:CarbonImmutable|null
     *     }
     * } $parsed
     */
    public function buildCurrentOnly(WeatherLocation $location, array $parsed): string
    {
        $current = $parsed['current'];
        $daily = $parsed['daily'];
        $date = $current['time']?->format('d.m.Y') ?? CarbonImmutable::now($location->timezoneOrDefault())->format('d.m.Y');

        return implode("\n", [
            $this->tr('bot.weather.report_title', [
                'emoji' => $this->weatherCodeEmoji($current['weather_code']),
                'location' => $location->name,
                'date' => $date,
            ]),
            '',
            ...$this->buildCurrentSectionLines($current),
            '',
            $this->tr('bot.weather.day_sunrise', [
                'emoji' => '🌅',
                'value' => $this->formatTime($daily['sunrise']),
            ]),
            $this->tr('bot.weather.day_sunset', [
                'emoji' => '🌇',
                'value' => $this->formatTime($daily['sunset']),
            ]),
        ]);
    }

    /**
     * @param array{
     *     current:array{time:CarbonImmutable|null,temperature_2m:float|null,apparent_temperature:float|null,weather_code:int|null,wind_speed_10m:float|null,wind_direction_10m:float|null},
     *     next_two_hours:array{temperature_min:float|null,temperature_max:float|null,apparent_temperature_min:float|null,apparent_temperature_max:float|null,wind_speed_min:float|null,wind_speed_max:float|null,precipitation_probability_max:float|null,weather_code:int|null},
     *     daily:array{
     *         weather_code:int|null,
     *         temperature_2m_min:float|null,
     *         temperature_2m_max:float|null,
     *         daytime_temperature_min:float|null,
     *         daytime_temperature_max:float|null,
     *         nighttime_temperature_min:float|null,
     *         nighttime_temperature_max:float|null,
     *         precipitation_probability_max:float|null,
     *         sunrise:CarbonImmutable|null,
     *         sunset:CarbonImmutable|null
     *     }
     * } $parsed
     */
    public function build(WeatherLocation $location, array $parsed): string
    {
        $current = $parsed['current'];
        $nextTwoHours = $parsed['next_two_hours'];
        $daily = $parsed['daily'];
        $titleWeatherCode = $current['weather_code'] ?? $daily['weather_code'] ?? $nextTwoHours['weather_code'] ?? null;

        $date = $current['time']?->format('d.m.Y') ?? CarbonImmutable::now($location->timezoneOrDefault())->format('d.m.Y');

        $lines = [
            $this->tr('bot.weather.report_title', [
                'emoji' => $this->weatherCodeEmoji($titleWeatherCode),
                'location' => $location->name,
                'date' => $date,
            ]),
            '',
            ...$this->buildCurrentSectionLines($current),
            '',
            $this->tr('bot.weather.next_two_hours_title'),
            $this->tr('bot.weather.next_two_hours_weather_code', [
                'emoji' => $this->weatherCodeEmoji($nextTwoHours['weather_code']),
                'value' => $this->formatWeatherCode($nextTwoHours['weather_code']),
            ]),
            $this->tr('bot.weather.next_two_hours_temperature', [
                'emoji' => '🌡️',
                'value' => $this->formatTemperatureRange(
                    $nextTwoHours['temperature_min'],
                    $nextTwoHours['temperature_max'],
                ),
            ]),
            $this->tr('bot.weather.next_two_hours_apparent_temperature', [
                'emoji' => $this->apparentTemperatureEmojiForRange(
                    $nextTwoHours['apparent_temperature_min'],
                    $nextTwoHours['apparent_temperature_max'],
                ),
                'value' => $this->formatTemperatureRange(
                    $nextTwoHours['apparent_temperature_min'],
                    $nextTwoHours['apparent_temperature_max'],
                ),
            ]),
            $this->tr('bot.weather.next_two_hours_wind', [
                'emoji' => '💨',
                'value' => $this->formatWindRange(
                    $nextTwoHours['wind_speed_min'],
                    $nextTwoHours['wind_speed_max'],
                ),
            ]),
            $this->tr('bot.weather.next_two_hours_precipitation_probability', [
                'value' => $this->formatProbability($nextTwoHours['precipitation_probability_max']),
            ]),
            '',
            $this->tr('bot.weather.day_title'),
            $this->tr('bot.weather.day_weather_code', [
                'emoji' => $this->weatherCodeEmoji($daily['weather_code']),
                'value' => $this->formatWeatherCode($daily['weather_code']),
            ]),
            $this->tr('bot.weather.day_temperature_daytime', [
                'emoji' => '☀️',
                'value' => $this->formatTemperatureRange(
                    $daily['daytime_temperature_min'],
                    $daily['daytime_temperature_max'],
                ),
            ]),
            $this->tr('bot.weather.day_temperature_nighttime', [
                'emoji' => '🌙',
                'value' => $this->formatTemperatureRange(
                    $daily['nighttime_temperature_min'],
                    $daily['nighttime_temperature_max'],
                ),
            ]),
            $this->tr('bot.weather.day_precipitation_probability', [
                'value' => $this->formatProbability($daily['precipitation_probability_max']),
            ]),
            $this->tr('bot.weather.day_sunrise', [
                'emoji' => '🌅',
                'value' => $this->formatTime($daily['sunrise']),
            ]),
            $this->tr('bot.weather.day_sunset', [
                'emoji' => '🌇',
                'value' => $this->formatTime($daily['sunset']),
            ]),
        ];

        return implode("\n", $lines);
    }

    /**
     * @param array{time:CarbonImmutable|null,temperature_2m:float|null,apparent_temperature:float|null,weather_code:int|null,wind_speed_10m:float|null,wind_direction_10m:float|null} $current
     * @return array<int, string>
     */
    private function buildCurrentSectionLines(array $current): array
    {
        return [
            $this->tr('bot.weather.current_title'),
            $this->tr('bot.weather.current_weather_code', [
                'emoji' => $this->weatherCodeEmoji($current['weather_code']),
                'value' => $this->formatWeatherCode($current['weather_code']),
            ]),
            $this->tr('bot.weather.current_temperature', [
                'emoji' => '🌡️',
                'value' => $this->formatTemperature($current['temperature_2m']),
            ]),
            $this->tr('bot.weather.current_apparent_temperature', [
                'emoji' => $this->apparentTemperatureEmoji(
                    $current['apparent_temperature'],
                    $current['temperature_2m'],
                ),
                'value' => $this->formatTemperature($current['apparent_temperature']),
            ]),
            $this->tr('bot.weather.current_wind', [
                'emoji' => '💨',
                'value' => $this->formatWind($current['wind_speed_10m']),
            ]),
            $this->tr('bot.weather.current_wind_direction', [
                'arrow' => $this->windDirectionArrow($current['wind_direction_10m']),
            ]),
        ];
    }

    private function formatTemperature(?float $value): string
    {
        if ($value === null) {
            return $this->tr('bot.weather.na');
        }

        return sprintf('%+d°C', (int) round($value));
    }

    private function formatTemperatureRange(?float $min, ?float $max): string
    {
        if ($min === null && $max === null) {
            return $this->tr('bot.weather.na');
        }

        if ($min === null || $max === null) {
            return $this->formatTemperature($min ?? $max);
        }

        return sprintf('%s…%s', $this->formatTemperature($min), $this->formatTemperature($max));
    }

    private function formatWind(?float $value): string
    {
        if ($value === null) {
            return $this->tr('bot.weather.na');
        }

        return sprintf('%d', (int) round($value));
    }

    private function formatWindRange(?float $min, ?float $max): string
    {
        if ($min === null && $max === null) {
            return $this->tr('bot.weather.na');
        }

        if ($min === null || $max === null) {
            return $this->formatWind($min ?? $max);
        }

        return sprintf('%d…%d', (int) round($min), (int) round($max));
    }

    private function formatWeatherCode(?int $code): string
    {
        if ($code === null) {
            return $this->tr('bot.weather.na');
        }

        return $this->weatherCodeDescription($code);
    }

    private function formatPercent(?float $value): string
    {
        if ($value === null) {
            return $this->tr('bot.weather.na');
        }

        return sprintf('%d%%', (int) round($value));
    }

    private function formatProbability(?float $value): string
    {
        if ($value === null) {
            return $this->tr('bot.weather.na');
        }

        $rounded = (int) round($value);

        if ($rounded === 0) {
            return '0%';
        }

        return sprintf('до %d%%', $rounded);
    }

    private function formatTime(?CarbonImmutable $value): string
    {
        if ($value === null) {
            return $this->tr('bot.weather.na');
        }

        return $value->format('H:i');
    }

    private function apparentTemperatureEmoji(?float $value, ?float $fallback = null): string
    {
        $candidate = $value ?? $fallback;

        if ($candidate === null) {
            return '💃';
        }

        if ($candidate <= 14) {
            return '🥶';
        }

        if ($candidate >= 28) {
            return '🥵';
        }

        return '💃';
    }

    private function apparentTemperatureEmojiForRange(?float $min, ?float $max): string
    {
        if ($min === null && $max === null) {
            return '💃';
        }

        if ($min !== null && $max !== null) {
            return $this->apparentTemperatureEmoji(($min + $max) / 2);
        }

        return $this->apparentTemperatureEmoji($min ?? $max);
    }

    private function windDirectionArrow(?float $degrees): string
    {
        if ($degrees === null) {
            return $this->tr('bot.weather.na');
        }

        $normalized = fmod(($degrees + 360.0), 360.0);

        return match (true) {
            $normalized >= 337.5 || $normalized < 22.5 => '⬆️',
            $normalized < 67.5 => '↗️',
            $normalized < 112.5 => '➡️',
            $normalized < 157.5 => '↘️',
            $normalized < 202.5 => '⬇️',
            $normalized < 247.5 => '↙️',
            $normalized < 292.5 => '⬅️',
            default => '↖️',
        };
    }

    private function weatherCodeEmoji(?int $code): string
    {
        return match ($code) {
            0 => '☀️',
            1, 2 => '⛅️',
            3 => '☁️',
            45, 48 => '🌫️',
            51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82 => '🌧️',
            71, 73, 75, 77, 85, 86 => '❄️',
            95, 96, 99 => '⛈️',
            default => '🌤️',
        };
    }

    private function weatherCodeDescription(?int $code): string
    {
        return match ($code) {
            0 => 'Ясно',
            1 => 'Преимущественно ясно',
            2 => 'Переменная облачность',
            3 => 'Пасмурно',
            45 => 'Туман',
            48 => 'Туман с инеем',
            51 => 'Слабая морось',
            53 => 'Умеренная морось',
            55 => 'Сильная морось',
            56 => 'Слабая переохлажденная морось',
            57 => 'Сильная переохлажденная морось',
            61 => 'Слабый дождь',
            63 => 'Умеренный дождь',
            65 => 'Сильный дождь',
            66 => 'Слабый ледяной дождь',
            67 => 'Сильный ледяной дождь',
            71 => 'Слабый снег',
            73 => 'Умеренный снег',
            75 => 'Сильный снег',
            77 => 'Снежные зерна',
            80 => 'Слабые ливни',
            81 => 'Умеренные ливни',
            82 => 'Сильные ливни',
            85 => 'Слабый снегопад',
            86 => 'Сильный снегопад',
            95 => 'Гроза',
            96 => 'Гроза с градом',
            99 => 'Сильная гроза с градом',
            default => $this->tr('bot.weather.na'),
        };
    }

    private function tr(string $key, array $replace = []): string
    {
        $escaped = [];

        foreach ($replace as $name => $value) {
            $escaped[$name] = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return trans($key, $escaped, $this->messageLocale());
    }

    private function messageLocale(): string
    {
        return (string) config('services.telegram.message_locale', 'ru');
    }
}
