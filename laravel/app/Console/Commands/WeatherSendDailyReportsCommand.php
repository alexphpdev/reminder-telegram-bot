<?php

namespace App\Console\Commands;

use App\Services\Weather\WeatherDailyReportService;
use Illuminate\Console\Command;

class WeatherSendDailyReportsCommand extends Command
{
    protected $signature = 'weather:send-daily-reports
        {--force : Send for all active locations, ignoring report window and per-day de-duplication}';

    protected $description = 'Send daily weather report to Telegram for due locations';

    public function handle(WeatherDailyReportService $weatherDailyReportService): int
    {
        $result = $weatherDailyReportService->dispatchDueReports(
            force: (bool) $this->option('force'),
        );

        $this->info(sprintf(
            'Processed %d location(s): %d sent, %d skipped, %d failed.',
            $result['processed'],
            $result['sent'],
            $result['skipped'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
