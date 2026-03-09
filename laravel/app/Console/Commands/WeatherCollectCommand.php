<?php

namespace App\Console\Commands;

use App\Services\Weather\WeatherCollector;
use Illuminate\Console\Command;

class WeatherCollectCommand extends Command
{
    protected $signature = 'weather:collect';

    protected $description = 'Collect weather snapshots for active locations via Open-Meteo';

    public function handle(WeatherCollector $weatherCollector): int
    {
        $result = $weatherCollector->collectActiveLocations();

        $this->info(sprintf(
            'Processed %d location(s): %d success, %d failed.',
            $result['processed'],
            $result['successful'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
