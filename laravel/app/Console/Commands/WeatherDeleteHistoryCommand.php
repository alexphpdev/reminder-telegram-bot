<?php

namespace App\Console\Commands;

use App\Models\WeatherHistory;
use Illuminate\Console\Command;

class WeatherDeleteHistoryCommand extends Command
{
    protected $signature = 'weather:delete-history
        {--all : Delete all rows from weather history}
        {--days= : Delete rows older than N days (integer, > 0)}';

    protected $description = 'Delete weather history rows manually (not scheduled)';

    public function handle(): int
    {
        $all = (bool) $this->option('all');
        $daysOption = $this->option('days');

        if (! $all && ($daysOption === null || $daysOption === '')) {
            $this->error('Use --all or --days=N.');

            return self::FAILURE;
        }

        if ($all) {
            $deleted = WeatherHistory::query()->delete();

            $this->info(sprintf('Deleted %d weather history row(s).', $deleted));

            return self::SUCCESS;
        }

        $days = (int) $daysOption;

        if ($days < 1) {
            $this->error('Option --days must be a positive integer.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $deleted = WeatherHistory::query()
            ->where('requested_at', '<', $cutoff)
            ->delete();

        $this->info(sprintf(
            'Deleted %d weather history row(s) older than %d day(s).',
            $deleted,
            $days,
        ));

        return self::SUCCESS;
    }
}
