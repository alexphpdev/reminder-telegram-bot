<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReminderDraft extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELED = 'canceled';

    public const STEP_AWAITING_TEXT = 'awaiting_text';
    public const STEP_AWAITING_DAY = 'awaiting_day';
    public const STEP_AWAITING_TIME = 'awaiting_time';
    public const STEP_AWAITING_STATUS_MODE = 'awaiting_status_mode';
    public const STEP_AWAITING_CONFIRM = 'awaiting_confirm';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'tracked_message_ids' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramChat::class, 'chat_id');
    }

    public function initiatorUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class, 'initiator_user_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class, 'target_user_id');
    }
}
