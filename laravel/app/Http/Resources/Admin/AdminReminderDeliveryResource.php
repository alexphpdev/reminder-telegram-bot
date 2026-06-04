<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;

class AdminReminderDeliveryResource extends AdminJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'delivery_status' => $this->delivery_status,
            'telegram_message_id' => $this->telegram_message_id,
            'message_text' => $this->message_text,
            'error_message' => $this->error_message,
            'sent_at' => $this->iso($this->sent_at),
            'acknowledged_at' => $this->iso($this->acknowledged_at),
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
