<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;

class AdminTelegramChatResource extends AdminJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'telegram_chat_id' => $this->telegram_chat_id,
            'title' => $this->title,
            'type' => $this->type,
            'username' => $this->username,
            'is_primary' => (bool) $this->is_primary,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
