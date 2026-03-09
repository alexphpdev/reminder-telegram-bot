<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeatherLocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function history(): HasMany
    {
        return $this->hasMany(WeatherHistory::class);
    }

    public function timezoneOrDefault(): string
    {
        $timezone = trim((string) $this->timezone);

        return $timezone !== '' ? $timezone : 'UTC';
    }
}
