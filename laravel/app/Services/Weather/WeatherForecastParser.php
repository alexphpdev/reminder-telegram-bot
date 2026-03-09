<?php

namespace App\Services\Weather;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class WeatherForecastParser
{
    /**
     * @return array{
     *     timezone:string,
     *     reference_time:CarbonImmutable,
     *     current:array{
     *         time:CarbonImmutable|null,
     *         temperature_2m:float|null,
     *         apparent_temperature:float|null,
     *         weather_code:int|null,
     *         wind_speed_10m:float|null,
     *         wind_direction_10m:float|null
     *     },
     *     next_two_hours:array{
     *         points:int,
     *         temperature_min:float|null,
     *         temperature_max:float|null,
     *         apparent_temperature_min:float|null,
     *         apparent_temperature_max:float|null,
     *         wind_speed_min:float|null,
     *         wind_speed_max:float|null,
     *         precipitation_probability_max:float|null,
     *         weather_codes:list<int>,
     *         weather_code:int|null
     *     },
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
     * }
     */
    public function parseDailyReportPayload(
        array $payload,
        string $timezone,
        ?CarbonImmutable $referenceTime = null,
    ): array {
        $reference = $referenceTime?->setTimezone($timezone) ?? CarbonImmutable::now($timezone);
        $currentTime = $this->parseDateTime(Arr::get($payload, 'current.time'), $timezone) ?? $reference;

        return [
            'timezone' => $timezone,
            'reference_time' => $reference,
            'current' => [
                'time' => $this->parseDateTime(Arr::get($payload, 'current.time'), $timezone),
                'temperature_2m' => $this->toFloat(Arr::get($payload, 'current.temperature_2m')),
                'apparent_temperature' => $this->toFloat(Arr::get($payload, 'current.apparent_temperature')),
                'weather_code' => $this->toInt(Arr::get($payload, 'current.weather_code')),
                'wind_speed_10m' => $this->toFloat(Arr::get($payload, 'current.wind_speed_10m')),
                'wind_direction_10m' => $this->toFloat(Arr::get($payload, 'current.wind_direction_10m')),
            ],
            'next_two_hours' => $this->parseNextTwoHours(
                Arr::get($payload, 'hourly', []),
                $timezone,
                $currentTime,
            ),
            'daily' => $this->parseDaily(
                Arr::get($payload, 'daily', []),
                Arr::get($payload, 'hourly', []),
                $timezone,
                $currentTime,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $hourly
     * @return array{
     *     points:int,
     *     temperature_min:float|null,
     *     temperature_max:float|null,
     *     apparent_temperature_min:float|null,
     *     apparent_temperature_max:float|null,
     *     wind_speed_min:float|null,
     *     wind_speed_max:float|null,
     *     precipitation_probability_max:float|null,
     *     weather_codes:list<int>,
     *     weather_code:int|null
     * }
     */
    private function parseNextTwoHours(array $hourly, string $timezone, CarbonImmutable $reference): array
    {
        $times = Arr::get($hourly, 'time', []);
        $candidateIndexes = [];

        if (is_array($times)) {
            foreach ($times as $index => $value) {
                $time = $this->parseDateTime($value, $timezone);

                if ($time !== null && $time->greaterThanOrEqualTo($reference)) {
                    $candidateIndexes[] = (int) $index;
                }
            }
        }

        if ($candidateIndexes === []) {
            $candidateIndexes = [0, 1];
        }

        $selectedIndexes = array_slice($candidateIndexes, 0, 2);

        $temperatures = $this->extractFloatValues(Arr::get($hourly, 'temperature_2m', []), $selectedIndexes);
        $apparentTemperatures = $this->extractFloatValues(Arr::get($hourly, 'apparent_temperature', []), $selectedIndexes);
        $windSpeeds = $this->extractFloatValues(Arr::get($hourly, 'wind_speed_10m', []), $selectedIndexes);
        $precipitationProbabilities = $this->extractFloatValues(
            Arr::get($hourly, 'precipitation_probability', []),
            $selectedIndexes,
        );
        $weatherCodes = array_values(array_unique($this->extractIntValues(
            Arr::get($hourly, 'weather_code', []),
            $selectedIndexes,
        )));

        return [
            'points' => count($selectedIndexes),
            'temperature_min' => $this->minValue($temperatures),
            'temperature_max' => $this->maxValue($temperatures),
            'apparent_temperature_min' => $this->minValue($apparentTemperatures),
            'apparent_temperature_max' => $this->maxValue($apparentTemperatures),
            'wind_speed_min' => $this->minValue($windSpeeds),
            'wind_speed_max' => $this->maxValue($windSpeeds),
            'precipitation_probability_max' => $this->maxValue($precipitationProbabilities),
            'weather_codes' => $weatherCodes,
            'weather_code' => $this->dominantIntValue($this->extractIntValues(
                Arr::get($hourly, 'weather_code', []),
                $selectedIndexes,
            )),
        ];
    }

    /**
     * @param array<string, mixed> $daily
     * @return array{
     *     weather_code:int|null,
     *     temperature_2m_min:float|null,
     *     temperature_2m_max:float|null,
     *     daytime_temperature_min:float|null,
     *     daytime_temperature_max:float|null,
     *     nighttime_temperature_min:float|null,
     *     nighttime_temperature_max:float|null,
     *     precipitation_probability_max:float|null,
     *     sunrise:CarbonImmutable|null,
     *     sunset:CarbonImmutable|null
     * }
     */
    private function parseDaily(array $daily, array $hourly, string $timezone, CarbonImmutable $reference): array
    {
        $targetDate = $reference->toDateString();
        $times = Arr::get($daily, 'time', []);
        $index = 0;

        if (is_array($times) && $times !== []) {
            foreach ($times as $candidateIndex => $value) {
                $time = $this->parseDateTime($value, $timezone);

                if ($time !== null && $time->toDateString() === $targetDate) {
                    $index = (int) $candidateIndex;
                    break;
                }
            }
        }

        $dayNightTemperatures = $this->extractDayNightTemperatureRanges(
            Arr::get($hourly, 'time', []),
            Arr::get($hourly, 'temperature_2m', []),
            $timezone,
            $reference,
        );

        return [
            'weather_code' => $this->toInt($this->getByIndex(Arr::get($daily, 'weather_code', []), $index)),
            'temperature_2m_min' => $this->toFloat($this->getByIndex(Arr::get($daily, 'temperature_2m_min', []), $index)),
            'temperature_2m_max' => $this->toFloat($this->getByIndex(Arr::get($daily, 'temperature_2m_max', []), $index)),
            'daytime_temperature_min' => $dayNightTemperatures['daytime_min'],
            'daytime_temperature_max' => $dayNightTemperatures['daytime_max'],
            'nighttime_temperature_min' => $dayNightTemperatures['nighttime_min'],
            'nighttime_temperature_max' => $dayNightTemperatures['nighttime_max'],
            'precipitation_probability_max' => $this->toFloat($this->getByIndex(
                Arr::get($daily, 'precipitation_probability_max', []),
                $index,
            )),
            'sunrise' => $this->parseDateTime($this->getByIndex(Arr::get($daily, 'sunrise', []), $index), $timezone),
            'sunset' => $this->parseDateTime($this->getByIndex(Arr::get($daily, 'sunset', []), $index), $timezone),
        ];
    }

    /**
     * @param mixed $values
     * @return list<float>
     */
    private function extractFloatValues(mixed $values, array $indexes): array
    {
        if (! is_array($values)) {
            return [];
        }

        $result = [];

        foreach ($indexes as $index) {
            $value = $this->toFloat($values[$index] ?? null);

            if ($value !== null) {
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * @param mixed $values
     * @return list<int>
     */
    private function extractIntValues(mixed $values, array $indexes): array
    {
        if (! is_array($values)) {
            return [];
        }

        $result = [];

        foreach ($indexes as $index) {
            $value = $this->toInt($values[$index] ?? null);

            if ($value !== null) {
                $result[] = $value;
            }
        }

        return $result;
    }

    private function minValue(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return min($values);
    }

    private function maxValue(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return max($values);
    }

    /**
     * @param list<int> $values
     */
    private function dominantIntValue(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        $counts = [];

        foreach ($values as $value) {
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        arsort($counts);
        $dominant = array_key_first($counts);

        return $dominant !== null ? (int) $dominant : null;
    }

    /**
     * @param mixed $hourlyTimes
     * @param mixed $hourlyTemperatures
     * @return array{
     *     daytime_min:float|null,
     *     daytime_max:float|null,
     *     nighttime_min:float|null,
     *     nighttime_max:float|null
     * }
     */
    private function extractDayNightTemperatureRanges(
        mixed $hourlyTimes,
        mixed $hourlyTemperatures,
        string $timezone,
        CarbonImmutable $reference,
    ): array {
        if (! is_array($hourlyTimes) || ! is_array($hourlyTemperatures)) {
            return [
                'daytime_min' => null,
                'daytime_max' => null,
                'nighttime_min' => null,
                'nighttime_max' => null,
            ];
        }

        $targetDate = $reference->toDateString();
        $nextDate = $reference->addDay()->toDateString();
        $daytimeTemperatures = [];
        $nighttimeTemperatures = [];

        foreach ($hourlyTimes as $index => $value) {
            $time = $this->parseDateTime($value, $timezone);
            $temperature = $this->toFloat($hourlyTemperatures[$index] ?? null);

            if ($time === null || $temperature === null) {
                continue;
            }

            $date = $time->toDateString();
            $hour = (int) $time->format('H');

            if ($date === $targetDate && $hour >= 7 && $hour <= 20) {
                $daytimeTemperatures[] = $temperature;
            }

            $isTargetLateNight = $date === $targetDate && $hour >= 23;
            $isNextEarlyMorning = $date === $nextDate && $hour <= 6;

            if ($isTargetLateNight || $isNextEarlyMorning) {
                $nighttimeTemperatures[] = $temperature;
            }
        }

        return [
            'daytime_min' => $this->minValue($daytimeTemperatures),
            'daytime_max' => $this->maxValue($daytimeTemperatures),
            'nighttime_min' => $this->minValue($nighttimeTemperatures),
            'nighttime_max' => $this->maxValue($nighttimeTemperatures),
        ];
    }

    private function getByIndex(mixed $values, int $index): mixed
    {
        if (! is_array($values)) {
            return null;
        }

        return $values[$index] ?? null;
    }

    private function toFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function toInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function parseDateTime(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }
}
