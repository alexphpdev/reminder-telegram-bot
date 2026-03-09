<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Reminder extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';

    public const SCHEDULE_ONCE = 'once';
    public const SCHEDULE_INTERVAL = 'interval';
    public const SCHEDULE_CRON = 'cron';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'next_run_at' => 'datetime',
            'snooze_until' => 'datetime',
            'ends_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'ask_status' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function scopeDue(Builder $query, CarbonInterface $now): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where(function (Builder $builder) use ($now): void {
                $builder
                    ->where(function (Builder $nested) use ($now): void {
                        $nested
                            ->whereNotNull('next_run_at')
                            ->where('next_run_at', '<=', $now);
                    })
                    ->orWhere(function (Builder $nested) use ($now): void {
                        $nested
                            ->whereNotNull('snooze_until')
                            ->where('snooze_until', '<=', $now);
                    });
            })
            ->where(function (Builder $builder) use ($now): void {
                $builder
                    ->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $builder) use ($now): void {
                $builder
                    ->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $now);
            });
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramChat::class, 'chat_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class, 'user_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ReminderDelivery::class);
    }
}
