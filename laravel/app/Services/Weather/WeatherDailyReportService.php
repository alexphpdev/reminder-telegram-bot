<?php

namespace App\Services\Weather;

use App\Models\TelegramChat;
use App\Models\WeatherHistory;
use App\Models\WeatherLocation;
use App\Services\Telegram\TelegramBotClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

class WeatherDailyReportService
{
    /**
     * Retry moments before report time (in minutes).
     * For report 07:00 this gives 06:55, 06:57, 06:59.
     */
    private const COLLECT_RETRY_OFFSETS_MINUTES = [5, 3, 1];

    public function __construct(
        private readonly OpenMeteoClient $openMeteoClient,
        private readonly TelegramBotClient $telegramBotClient,
        private readonly WeatherForecastParser $weatherForecastParser,
        private readonly WeatherDailyReportMessageBuilder $messageBuilder,
        private readonly CacheRepository $cache,
    ) {
    }

    /**
     * @return array{processed:int,collected:int,skipped:int,failed:int}
     */
    public function collectDueSnapshots(?Carbon $now = null, bool $force = false): array
    {
        $currentTime = CarbonImmutable::instance($now ?? now());

        $result = [
            'processed' => 0,
            'collected' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $locations = WeatherLocation::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($locations as $location) {
            $result['processed']++;

            $window = $this->buildWindow($location, $currentTime);

            if (! $force && ! $this->isCollectAttemptMinute($window)) {
                $result['skipped']++;
                continue;
            }

            if (! $force && $this->hasSnapshotForCurrentWindow($location, $window)) {
                $result['skipped']++;
                continue;
            }

            try {
                $apiResponse = $this->openMeteoClient->forecast($location, $this->dailyReportQuery());

                WeatherHistory::query()->create([
                    'weather_location_id' => $location->id,
                    'provider' => 'open-meteo',
                    'response_status' => WeatherHistory::STATUS_SUCCESS,
                    'http_status' => (int) ($apiResponse['http_status'] ?? 200),
                    'requested_at' => $currentTime->toMutable(),
                    'response_payload' => $apiResponse['payload'] ?? null,
                    'error_message' => null,
                ]);

                $result['collected']++;
            } catch (Throwable $exception) {
                report($exception);

                WeatherHistory::query()->create([
                    'weather_location_id' => $location->id,
                    'provider' => 'open-meteo',
                    'response_status' => WeatherHistory::STATUS_FAILED,
                    'http_status' => null,
                    'requested_at' => $currentTime->toMutable(),
                    'response_payload' => null,
                    'error_message' => $exception->getMessage(),
                ]);

                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * @return array{processed:int,sent:int,skipped:int,failed:int}
     */
    public function dispatchDueReports(?Carbon $now = null, bool $force = false): array
    {
        $currentTime = CarbonImmutable::instance($now ?? now());

        $result = [
            'processed' => 0,
            'sent' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $locations = WeatherLocation::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($locations as $location) {
            $result['processed']++;

            $window = $this->buildWindow($location, $currentTime);
            $cacheKey = $this->reportSentCacheKey($location, $window['report_date']);

            if (! $force && ! $this->isReportMinute($window)) {
                $result['skipped']++;
                continue;
            }

            if (! $force && $this->cache->has($cacheKey)) {
                $result['skipped']++;
                continue;
            }

            try {
                $snapshot = $this->latestSnapshotForReportWindow($location, $window);
                $message = $this->buildDispatchMessage($location, $window, $snapshot);

                $this->telegramBotClient->sendMessage(
                    $this->resolveChatId(),
                    $message,
                    [
                        'parse_mode' => 'HTML',
                        'disable_notification' => $this->disableNotification(),
                    ],
                );

                if (! $force) {
                    $this->cache->put($cacheKey, $currentTime->toIso8601String(), now()->addDays(2));
                }

                $result['sent']++;
            } catch (Throwable $exception) {
                report($exception);
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * @return array{
     *     report_date:string,
     *     local_now:CarbonImmutable,
     *     report_at_local:CarbonImmutable,
     *     collect_from_local:CarbonImmutable,
     *     report_at_app_timezone:CarbonImmutable,
     *     collect_from_app_timezone:CarbonImmutable
     * }
     */
    private function buildWindow(WeatherLocation $location, CarbonImmutable $now): array
    {
        [$hour, $minute] = $this->dailyReportTimeParts();

        $timezone = $location->timezoneOrDefault();
        $localNow = $now->setTimezone($timezone);
        $reportAtLocal = $localNow->setTime($hour, $minute);
        $collectFromLocal = $reportAtLocal->subMinutes(max(self::COLLECT_RETRY_OFFSETS_MINUTES));

        return [
            'report_date' => $reportAtLocal->toDateString(),
            'local_now' => $localNow,
            'report_at_local' => $reportAtLocal,
            'collect_from_local' => $collectFromLocal,
            'report_at_app_timezone' => $this->toAppTimezone($reportAtLocal),
            'collect_from_app_timezone' => $this->toAppTimezone($collectFromLocal),
        ];
    }

    /**
     * @param array{
     *     local_now:CarbonImmutable,
     *     report_at_local:CarbonImmutable
     * } $window
     */
    private function isCollectAttemptMinute(array $window): bool
    {
        $localNow = $window['local_now']->format('H:i');
        $reportAt = $window['report_at_local'];

        foreach (self::COLLECT_RETRY_OFFSETS_MINUTES as $offset) {
            if ($localNow === $reportAt->subMinutes($offset)->format('H:i')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{
     *     local_now:CarbonImmutable,
     *     report_at_local:CarbonImmutable
     * } $window
     */
    private function isReportMinute(array $window): bool
    {
        return $window['local_now']->format('H:i') === $window['report_at_local']->format('H:i');
    }

    /**
     * @param array{
     *     report_at_app_timezone:CarbonImmutable,
     *     collect_from_app_timezone:CarbonImmutable
     * } $window
     */
    private function hasSnapshotForCurrentWindow(WeatherLocation $location, array $window): bool
    {
        return WeatherHistory::query()
            ->where('weather_location_id', $location->id)
            ->where('response_status', WeatherHistory::STATUS_SUCCESS)
            ->where('requested_at', '>=', $window['collect_from_app_timezone']->toMutable())
            ->where('requested_at', '<', $window['report_at_app_timezone']->toMutable())
            ->exists();
    }

    /**
     * @param array{
     *     report_at_app_timezone:CarbonImmutable,
     *     collect_from_app_timezone:CarbonImmutable
     * } $window
     */
    private function latestSnapshotForReportWindow(WeatherLocation $location, array $window): ?WeatherHistory
    {
        return WeatherHistory::query()
            ->where('weather_location_id', $location->id)
            ->where('response_status', WeatherHistory::STATUS_SUCCESS)
            ->where('requested_at', '>=', $window['collect_from_app_timezone']->toMutable())
            ->where('requested_at', '<', $window['report_at_app_timezone']->toMutable())
            ->orderByDesc('requested_at')
            ->first();
    }

    /**
     * @param array{
     *     report_at_local:CarbonImmutable
     * } $window
     */
    private function buildDispatchMessage(
        WeatherLocation $location,
        array $window,
        ?WeatherHistory $snapshot,
    ): string {
        if ($snapshot === null || ! is_array($snapshot->response_payload)) {
            return $this->tr('bot.weather.report_unavailable', [
                'location' => $location->name,
                'date' => $window['report_at_local']->format('d.m.Y'),
            ]);
        }

        $parsed = $this->weatherForecastParser->parseDailyReportPayload(
            $snapshot->response_payload,
            $location->timezoneOrDefault(),
            $window['report_at_local'],
        );

        return $this->messageBuilder->build($location, $parsed);
    }

    private function resolveChatId(): int|string
    {
        $configuredPrimaryChatId = config('services.telegram.primary_chat_id');

        if ($configuredPrimaryChatId !== null && $configuredPrimaryChatId !== '') {
            return $configuredPrimaryChatId;
        }

        $databasePrimaryChatId = TelegramChat::query()
            ->where('is_primary', true)
            ->value('telegram_chat_id');

        if ($databasePrimaryChatId !== null) {
            return $databasePrimaryChatId;
        }

        throw new RuntimeException('No Telegram chat configured for weather reports.');
    }

    /**
     * @return array{
     *     forecast_days:int,
     *     current:string,
     *     hourly:string,
     *     daily:string
     * }
     */
    private function dailyReportQuery(): array
    {
        return [
            'forecast_days' => 2,
            'current' => 'temperature_2m,apparent_temperature,weather_code,wind_speed_10m,wind_direction_10m',
            'hourly' => 'temperature_2m,apparent_temperature,weather_code,wind_speed_10m,precipitation_probability',
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,sunrise,sunset',
        ];
    }

    private function dailyReportTimeParts(): array
    {
        $value = (string) config('weather.daily_report.time', '07:00');

        if (! preg_match('/^\s*(\d{1,2}):(\d{2})\s*$/', $value, $matches)) {
            return [7, 0];
        }

        return [
            max(0, min(23, (int) $matches[1])),
            max(0, min(59, (int) $matches[2])),
        ];
    }

    private function disableNotification(): bool
    {
        return (bool) config('weather.daily_report.disable_notification', false);
    }

    private function toAppTimezone(CarbonImmutable $value): CarbonImmutable
    {
        return $value->setTimezone((string) config('app.timezone', 'UTC'));
    }

    private function reportSentCacheKey(WeatherLocation $location, string $reportDate): string
    {
        return sprintf('weather:daily_report:sent:%d:%s', $location->id, $reportDate);
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
