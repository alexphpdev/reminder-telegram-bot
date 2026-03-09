<?php

namespace App\Console\Commands;

use App\Services\Weather\WeatherDailyReportService;
use Illuminate\Console\Command;

class WeatherCollectDailySnapshotsCommand extends Command
{
    protected $signature = 'weather:collect-daily-snapshots
        {--force : Collect for all active locations, ignoring the pre-report window}';

    protected $description = 'Collect weather snapshots for daily 07:00 weather report';

    public function handle(WeatherDailyReportService $weatherDailyReportService): int
    {
        $result = $weatherDailyReportService->collectDueSnapshots(
            force: (bool) $this->option('force'),
        );

        $this->info(sprintf(
            'Processed %d location(s): %d collected, %d skipped, %d failed.',
            $result['processed'],
            $result['collected'],
            $result['skipped'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
