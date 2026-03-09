<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class TelegramUser extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_bot' => 'boolean',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function timezoneOrDefault(): string
    {
        $timezone = trim((string) ($this->timezone ?? ''));

        return $timezone !== '' ? $timezone : 'UTC';
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class, 'user_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(TelegramUpdate::class, 'user_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ReminderDelivery::class, 'user_id');
    }

    public function getDisplayNameAttribute(): string
    {
        $pseudonym = trim((string) ($this->pseudonym ?? ''));

        if ($pseudonym !== '') {
            return $pseudonym;
        }

        $firstName = trim((string) ($this->first_name ?? ''));

        if ($firstName !== '') {
            return $firstName;
        }

        $displayName = trim(implode(' ', array_filter([
            $this->first_name,
            $this->last_name,
        ])));

        if ($displayName !== '') {
            return $displayName;
        }

        if ($this->username !== null) {
            return '@'.$this->username;
        }

        return (string) $this->telegram_user_id;
    }

    public function telegramMention(): ?string
    {
        if ($this->telegram_user_id === null) {
            return null;
        }

        return sprintf(
            '<a href="tg://user?id=%s">%s</a>',
            $this->telegram_user_id,
            e($this->display_name),
        );
    }
}
