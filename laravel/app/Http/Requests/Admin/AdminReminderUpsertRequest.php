<?php

namespace App\Http\Requests\Admin;

use App\Models\Reminder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminReminderUpsertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string'],
            'chat_id' => ['nullable', 'integer', 'exists:telegram_chats,id'],
            'user_id' => ['nullable', 'integer', 'exists:telegram_users,id'],
            'mention_override' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in([
                Reminder::STATUS_ACTIVE,
                Reminder::STATUS_PAUSED,
            ])],
            'schedule_type' => ['required', Rule::in([
                Reminder::SCHEDULE_ONCE,
                Reminder::SCHEDULE_INTERVAL,
                Reminder::SCHEDULE_CRON,
            ])],
            'timezone' => ['required', 'timezone'],
            'ask_status' => ['required', 'boolean'],
            'scheduled_for_local' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'starts_at_local' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'ends_at_local' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'interval_minutes' => ['nullable', 'integer', 'min:1'],
            'cron_expression' => ['nullable', 'string', 'max:255'],
        ];
    }
}
