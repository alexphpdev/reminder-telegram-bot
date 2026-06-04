<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;

class AdminTelegramUserSummaryResource extends AdminJsonResource
{
    protected function summary(): array
    {
        return [
            'id' => $this->id,
            'telegram_user_id' => $this->telegram_user_id,
            'username' => $this->username,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'pseudonym' => $this->pseudonym,
            'display_name' => $this->display_name,
            'language_code' => $this->language_code,
            'timezone' => $this->timezone,
            'is_bot' => (bool) $this->is_bot,
            'is_active' => (bool) $this->is_active,
            'reminders_count' => $this->reminders_count,
            'active_reminders_count' => $this->active_reminders_count,
            'paused_reminders_count' => $this->paused_reminders_count,
            'completed_reminders_count' => $this->completed_reminders_count,
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }

    public function toArray(Request $request): array
    {
        return $this->summary();
    }
}
