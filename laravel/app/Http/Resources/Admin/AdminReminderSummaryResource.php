<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;

class AdminReminderSummaryResource extends AdminJsonResource
{
    protected function summary(): array
    {
        return [
            'id' => $this->id,
            'message' => $this->message,
            'status' => $this->status,
            'schedule_type' => $this->schedule_type,
            'cron_expression' => $this->cron_expression,
            'interval_minutes' => $this->interval_minutes,
            'timezone' => $this->timezone,
            'mention_override' => $this->mention_override,
            'ask_status' => (bool) $this->ask_status,
            'user_id' => $this->user_id,
            'chat_id' => $this->chat_id,
            'starts_at' => $this->iso($this->starts_at),
            'next_run_at' => $this->iso($this->next_run_at),
            'snooze_until' => $this->iso($this->snooze_until),
            'ends_at' => $this->iso($this->ends_at),
            'last_sent_at' => $this->iso($this->last_sent_at),
            'deliveries_count' => $this->deliveries_count,
            'user' => $this->relationLoaded('user')
                ? ($this->user ? new AdminTelegramUserSummaryResource($this->user) : null)
                : null,
            'chat' => $this->relationLoaded('chat')
                ? ($this->chat ? new AdminTelegramChatResource($this->chat) : null)
                : null,
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }

    public function toArray(Request $request): array
    {
        return $this->summary();
    }
}
