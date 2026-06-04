<?php

namespace App\Services\Reminders;

use App\Models\Reminder;
use App\Models\TelegramChat;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Validation\ValidationException;

class AdminReminderManager
{
    public function __construct(
        private readonly ReminderScheduleService $scheduleService,
    ) {
    }

    public function create(array $payload): Reminder
    {
        $attributes = $this->buildAttributes($payload);
        $attributes['meta'] = [
            'source' => 'admin_api',
            'created_via' => 'admin_api',
        ];

        $reminder = Reminder::query()->create($attributes);

        return $this->loadDetail($reminder);
    }

    public function update(Reminder $reminder, array $payload): Reminder
    {
        $reminder->forceFill($this->buildAttributes($payload))->save();

        return $this->loadDetail($reminder);
    }

    public function delete(Reminder $reminder): void
    {
        $reminder->delete();
    }

    private function buildAttributes(array $payload): array
    {
        $errors = [];
        $message = trim((string) ($payload['message'] ?? ''));

        if ($message === '') {
            $errors['message'][] = 'Message is required.';
        }

        $chatId = $payload['chat_id'] ?? null;

        if ($chatId === null && ! $this->hasFallbackChatTarget()) {
            $errors['chat_id'][] = 'Select a chat or configure a primary Telegram chat first.';
        }

        $timezone = trim((string) ($payload['timezone'] ?? ''));
        $now = $this->scheduleService->appNow();
        $scheduleAttributes = $this->buildScheduleAttributes($payload, $timezone, $now, $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $mentionOverride = trim((string) ($payload['mention_override'] ?? ''));

        return [
            'message' => $message,
            'chat_id' => $chatId,
            'user_id' => $payload['user_id'] ?? null,
            'mention_override' => $mentionOverride !== '' ? $mentionOverride : null,
            'status' => $payload['status'],
            'schedule_type' => $payload['schedule_type'],
            'cron_expression' => $scheduleAttributes['cron_expression'],
            'interval_minutes' => $scheduleAttributes['interval_minutes'],
            'timezone' => $timezone,
            'starts_at' => $scheduleAttributes['starts_at'],
            'next_run_at' => $scheduleAttributes['next_run_at'],
            'ends_at' => $scheduleAttributes['ends_at'],
            'snooze_until' => null,
            'ask_status' => (bool) $payload['ask_status'],
        ];
    }

    private function buildScheduleAttributes(
        array $payload,
        string $timezone,
        CarbonImmutable $now,
        array &$errors,
    ): array {
        $scheduledForLocalInput = $this->trimToNull($payload['scheduled_for_local'] ?? null);
        $startsAtLocalInput = $this->trimToNull($payload['starts_at_local'] ?? null);
        $endsAtLocalInput = $this->trimToNull($payload['ends_at_local'] ?? null);
        $cronExpression = $this->trimToNull($payload['cron_expression'] ?? null);
        $intervalMinutes = $payload['interval_minutes'] ?? null;

        $scheduledForLocal = $this->scheduleService->parseLocalDateTime($scheduledForLocalInput, $timezone);
        $startsAtLocal = $this->scheduleService->parseLocalDateTime($startsAtLocalInput, $timezone);
        $endsAtLocal = $this->scheduleService->parseLocalDateTime($endsAtLocalInput, $timezone);

        if ($scheduledForLocalInput !== null && $scheduledForLocal === null) {
            $errors['scheduled_for_local'][] = 'Scheduled time is invalid.';
        }

        if ($startsAtLocalInput !== null && $startsAtLocal === null) {
            $errors['starts_at_local'][] = 'Start time is invalid.';
        }

        if ($endsAtLocalInput !== null && $endsAtLocal === null) {
            $errors['ends_at_local'][] = 'End time is invalid.';
        }

        if ($errors !== []) {
            return $this->emptyScheduleAttributes();
        }

        $scheduleType = $payload['schedule_type'];
        $startsAt = null;
        $endsAt = null;
        $nextRunAt = null;

        if ($scheduleType === Reminder::SCHEDULE_ONCE) {
            if ($scheduledForLocal === null) {
                $errors['scheduled_for_local'][] = 'One-time reminders require scheduled_for_local.';
            }

            if ($startsAtLocalInput !== null) {
                $errors['starts_at_local'][] = 'starts_at_local is not used for one-time reminders.';
            }

            if ($endsAtLocalInput !== null) {
                $errors['ends_at_local'][] = 'ends_at_local is not used for one-time reminders.';
            }

            if ($intervalMinutes !== null) {
                $errors['interval_minutes'][] = 'interval_minutes is only used for interval reminders.';
            }

            if ($cronExpression !== null) {
                $errors['cron_expression'][] = 'cron_expression is only used for cron reminders.';
            }

            if ($scheduledForLocal !== null && $scheduledForLocal->lessThanOrEqualTo($now->setTimezone($timezone))) {
                $errors['scheduled_for_local'][] = 'One-time reminders must be scheduled in the future.';
            }

            $nextRunAt = $scheduledForLocal !== null
                ? $this->scheduleService->toAppTimezone($scheduledForLocal)
                : null;
        }

        if ($scheduleType === Reminder::SCHEDULE_INTERVAL) {
            if ($startsAtLocal === null) {
                $errors['starts_at_local'][] = 'Interval reminders require starts_at_local.';
            }

            if ($intervalMinutes === null) {
                $errors['interval_minutes'][] = 'Interval reminders require interval_minutes.';
            }

            if ($scheduledForLocalInput !== null) {
                $errors['scheduled_for_local'][] = 'scheduled_for_local is only used for one-time reminders.';
            }

            if ($cronExpression !== null) {
                $errors['cron_expression'][] = 'cron_expression is only used for cron reminders.';
            }

            if ($startsAtLocal !== null && $endsAtLocal !== null && $endsAtLocal->lessThanOrEqualTo($startsAtLocal)) {
                $errors['ends_at_local'][] = 'End time must be after start time.';
            }

            if ($startsAtLocal !== null && is_int($intervalMinutes)) {
                $startsAt = $this->scheduleService->toAppTimezone($startsAtLocal);
                $endsAt = $endsAtLocal !== null ? $this->scheduleService->toAppTimezone($endsAtLocal) : null;
                $nextRunAt = $this->scheduleService->initialIntervalRunAt($startsAtLocal, $intervalMinutes, $now);

                if ($endsAt !== null && $nextRunAt !== null && $nextRunAt->greaterThan($endsAt)) {
                    $errors['ends_at_local'][] = 'Interval reminder has no future run before the configured end time.';
                }
            }
        }

        if ($scheduleType === Reminder::SCHEDULE_CRON) {
            if ($cronExpression === null) {
                $errors['cron_expression'][] = 'Cron reminders require cron_expression.';
            } elseif (! CronExpression::isValidExpression($cronExpression)) {
                $errors['cron_expression'][] = 'Cron expression is invalid.';
            }

            if ($intervalMinutes !== null) {
                $errors['interval_minutes'][] = 'interval_minutes is only used for interval reminders.';
            }

            if ($scheduledForLocalInput !== null) {
                $errors['scheduled_for_local'][] = 'scheduled_for_local is only used for one-time reminders.';
            }

            if ($startsAtLocal !== null && $endsAtLocal !== null && $endsAtLocal->lessThanOrEqualTo($startsAtLocal)) {
                $errors['ends_at_local'][] = 'End time must be after start time.';
            }

            if ($cronExpression !== null && CronExpression::isValidExpression($cronExpression)) {
                $startsAt = $startsAtLocal !== null ? $this->scheduleService->toAppTimezone($startsAtLocal) : null;
                $endsAt = $endsAtLocal !== null ? $this->scheduleService->toAppTimezone($endsAtLocal) : null;
                $nextRunAt = $this->scheduleService->initialCronRunAt($startsAtLocal, $cronExpression, $timezone, $now);

                if ($nextRunAt === null) {
                    $errors['cron_expression'][] = 'Cron reminder next run could not be calculated.';
                } elseif ($endsAt !== null && $nextRunAt->greaterThan($endsAt)) {
                    $errors['ends_at_local'][] = 'Cron reminder has no future run before the configured end time.';
                }
            }
        }

        if ($errors !== []) {
            return $this->emptyScheduleAttributes();
        }

        return [
            'starts_at' => $startsAt,
            'next_run_at' => $nextRunAt,
            'ends_at' => $endsAt,
            'interval_minutes' => $scheduleType === Reminder::SCHEDULE_INTERVAL ? $intervalMinutes : null,
            'cron_expression' => $scheduleType === Reminder::SCHEDULE_CRON ? $cronExpression : null,
        ];
    }

    private function hasFallbackChatTarget(): bool
    {
        $configuredPrimaryChatId = trim((string) config('services.telegram.primary_chat_id', ''));

        if ($configuredPrimaryChatId !== '') {
            return true;
        }

        return TelegramChat::query()->where('is_primary', true)->exists();
    }

    private function loadDetail(Reminder $reminder): Reminder
    {
        $reminder->load([
            'chat',
            'user',
            'deliveries' => fn ($builder) => $builder
                ->latest('sent_at')
                ->latest('id')
                ->limit(5),
        ]);
        $reminder->loadCount('deliveries');

        return $reminder;
    }

    private function emptyScheduleAttributes(): array
    {
        return [
            'starts_at' => null,
            'next_run_at' => null,
            'ends_at' => null,
            'interval_minutes' => null,
            'cron_expression' => null,
        ];
    }

    private function trimToNull(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
