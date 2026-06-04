<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;

class AdminTelegramUserDetailResource extends AdminTelegramUserSummaryResource
{
    public function toArray(Request $request): array
    {
        return array_merge($this->summary(), [
            'recent_reminders' => AdminReminderSummaryResource::collection($this->whenLoaded('reminders')),
        ]);
    }
}
