<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class TelegramChat extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class, 'chat_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(TelegramUpdate::class, 'chat_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ReminderDelivery::class, 'chat_id');
    }
}
