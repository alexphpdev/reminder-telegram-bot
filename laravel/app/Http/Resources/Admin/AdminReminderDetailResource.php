<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;

class AdminReminderDetailResource extends AdminReminderSummaryResource
{
    public function toArray(Request $request): array
    {
        return array_merge($this->summary(), [
            'recent_deliveries' => AdminReminderDeliveryResource::collection($this->whenLoaded('deliveries')),
        ]);
    }
}
