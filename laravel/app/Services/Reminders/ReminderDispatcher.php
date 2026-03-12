<?php

namespace App\Services\Reminders;

use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\TelegramChat;
use App\Services\Telegram\TelegramBotClient;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

class ReminderDispatcher
{
    public function __construct(
        private readonly TelegramBotClient $telegramBotClient,
    ) {
    }

    public function dispatchDueReminders(?Carbon $now = null): int
    {
        $currentTime = $this->toAppTimezone(CarbonImmutable::instance($now ?? now()));
        $processed = $this->dispatchExpiredStatusDeliveries($currentTime);

        $reminders = Reminder::query()
            ->with(['chat', 'user'])
            ->due($currentTime->toMutable())
            ->orderBy('next_run_at')
            ->get();

        foreach ($reminders as $reminder) {
            try {
                if ($this->dispatch($reminder, $currentTime)) {
                    $processed++;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $processed;
    }

    private function dispatchExpiredStatusDeliveries(CarbonImmutable $now): int
    {
        $timeoutMinutes = $this->statusResponseTimeoutMinutes();

        if ($timeoutMinutes < 1) {
            return 0;
        }

        $cutoff = $now->subMinutes($timeoutMinutes);

        $staleDeliveries = ReminderDelivery::query()
            ->with(['chat', 'reminder.chat', 'reminder.user'])
            ->where('delivery_status', ReminderDelivery::STATUS_SENT)
            ->whereNull('acknowledged_at')
            ->whereNotNull('sent_at')
            ->where('sent_at', '<=', $cutoff)
            ->whereHas('reminder', function ($query): void {
                $query->where('ask_status', true);
            })
            ->orderBy('sent_at')
            ->get();

        $processed = 0;

        foreach ($staleDeliveries as $staleDelivery) {
            try {
                if ($this->dispatchExpiredStatusDelivery($staleDelivery, $now)) {
                    $processed++;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $processed;
    }

    private function dispatch(Reminder $reminder, CarbonImmutable $now): bool
    {
        $dispatchingSnooze = $this->dispatchingSnooze($reminder, $now);
        $messageText = $this->buildMessage($reminder);

        $delivery = $reminder->deliveries()->create([
            'chat_id' => $reminder->chat_id,
            'user_id' => $reminder->user_id,
            'delivery_status' => ReminderDelivery::STATUS_DISPATCHING,
            'message_text' => $messageText,
        ]);

        try {
            $response = $this->telegramBotClient->sendMessage(
                $this->resolveChatId($reminder),
                $messageText,
                array_filter([
                    'parse_mode' => 'HTML',
                    'reply_markup' => $reminder->ask_status ? $this->buildReplyMarkup($delivery) : null,
                ], fn (mixed $value): bool => $value !== null),
            );
        } catch (Throwable $exception) {
            $delivery->forceFill([
                'delivery_status' => ReminderDelivery::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        $delivery->forceFill([
            'delivery_status' => ReminderDelivery::STATUS_SENT,
            'telegram_message_id' => data_get($response, 'result.message_id'),
            'response_payload' => $response,
            'sent_at' => $this->toAppTimezone($now),
        ])->save();

        if ($dispatchingSnooze) {
            $reminder->forceFill([
                'last_sent_at' => $this->toAppTimezone($now),
                'snooze_until' => null,
                'status' => $reminder->next_run_at === null ? Reminder::STATUS_COMPLETED : Reminder::STATUS_ACTIVE,
            ])->save();
        } else {
            $nextRunAt = $this->calculateNextRunAt($reminder, $now);

            $reminder->forceFill([
                'last_sent_at' => $this->toAppTimezone($now),
                'next_run_at' => $nextRunAt,
                'snooze_until' => null,
                'status' => $nextRunAt === null ? Reminder::STATUS_COMPLETED : $reminder->status,
            ])->save();
        }

        return true;
    }

    private function dispatchExpiredStatusDelivery(ReminderDelivery $staleDelivery, CarbonImmutable $now): bool
    {
        $reminder = $staleDelivery->reminder;

        if ($reminder === null) {
            return false;
        }

        $messageText = trim((string) $staleDelivery->message_text) !== ''
            ? $staleDelivery->message_text
            : $this->buildMessage($reminder);

        $replacementDelivery = $reminder->deliveries()->create([
            'chat_id' => $staleDelivery->chat_id ?? $reminder->chat_id,
            'user_id' => $staleDelivery->user_id ?? $reminder->user_id,
            'delivery_status' => ReminderDelivery::STATUS_DISPATCHING,
            'message_text' => $messageText,
        ]);

        try {
            $response = $this->telegramBotClient->sendMessage(
                $this->resolveChatId($reminder),
                $messageText,
                [
                    'parse_mode' => 'HTML',
                    'reply_markup' => $this->buildReplyMarkup($replacementDelivery),
                ],
            );
        } catch (Throwable $exception) {
            $replacementDelivery->forceFill([
                'delivery_status' => ReminderDelivery::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        $replacementDelivery->forceFill([
            'delivery_status' => ReminderDelivery::STATUS_SENT,
            'telegram_message_id' => data_get($response, 'result.message_id'),
            'response_payload' => $response,
            'sent_at' => $this->toAppTimezone($now),
        ])->save();

        $expirationMeta = [
            'expired_at' => $this->toAppTimezone($now)->toIso8601String(),
            'replaced_by_delivery_id' => $replacementDelivery->id,
        ];

        $expirationMeta = array_merge(
            $expirationMeta,
            $this->deleteDeliveryMessage($staleDelivery, $reminder),
        );

        $statusPayload = is_array($staleDelivery->status_payload) ? $staleDelivery->status_payload : [];
        $statusPayload['_expired'] = $expirationMeta;

        $staleDelivery->forceFill([
            'delivery_status' => ReminderDelivery::STATUS_EXPIRED,
            'status_payload' => $statusPayload,
        ])->save();

        return true;
    }

    private function dispatchingSnooze(Reminder $reminder, CarbonImmutable $now): bool
    {
        if ($reminder->snooze_until === null) {
            return false;
        }

        $snoozeUntil = CarbonImmutable::instance($reminder->snooze_until);

        if ($snoozeUntil->greaterThan($now)) {
            return false;
        }

        if ($reminder->next_run_at === null) {
            return true;
        }

        $nextRunAt = CarbonImmutable::instance($reminder->next_run_at);

        return $nextRunAt->greaterThan($now) || $snoozeUntil->lessThanOrEqualTo($nextRunAt);
    }

    private function resolveChatId(Reminder $reminder): int|string
    {
        if ($reminder->chat?->telegram_chat_id !== null) {
            return $reminder->chat->telegram_chat_id;
        }

        $configuredPrimaryChatId = config('services.telegram.primary_chat_id');

        if ($configuredPrimaryChatId !== null && $configuredPrimaryChatId !== '') {
            return $configuredPrimaryChatId;
        }

        $databasePrimaryChatId = TelegramChat::query()
            ->where('is_primary', true)
            ->value('telegram_chat_id');

        if ($databasePrimaryChatId !== null) {
            return $databasePrimaryChatId;
        }

        throw new RuntimeException(sprintf('No Telegram chat configured for reminder #%d.', $reminder->id));
    }

    private function buildMessage(Reminder $reminder): string
    {
        $mention = $reminder->mention_override !== null && $reminder->mention_override !== ''
            ? e($reminder->mention_override)
            : $reminder->user?->telegramMention();

        $parts = array_filter([
            $mention,
            e($reminder->message),
            $reminder->ask_status ? $this->tr('bot.reminders.status_prompt') : null,
        ], fn (?string $value): bool => $value !== null && $value !== '');

        return implode("\n\n", $parts);
    }

    private function buildReplyMarkup(ReminderDelivery $delivery): array
    {
        return [
            'inline_keyboard' => [[
                [
                    'text' => $this->tr('bot.reminders.button_done'),
                    'callback_data' => sprintf('delivery:%d:done', $delivery->id),
                ],
                [
                    'text' => $this->tr('bot.reminders.button_later'),
                    'callback_data' => sprintf('delivery:%d:later', $delivery->id),
                ],
            ]],
        ];
    }

    private function calculateNextRunAt(Reminder $reminder, CarbonImmutable $now): ?CarbonImmutable
    {
        $timezone = $this->reminderTimezone($reminder);
        $currentDueTime = CarbonImmutable::instance($reminder->next_run_at ?? $now)->setTimezone($timezone);
        $referenceNow = $now->setTimezone($timezone);

        $nextRunAt = match ($reminder->schedule_type) {
            Reminder::SCHEDULE_ONCE => null,
            Reminder::SCHEDULE_INTERVAL => $this->nextIntervalRun($reminder, $currentDueTime, $referenceNow),
            Reminder::SCHEDULE_CRON => $this->nextCronRun($reminder, $currentDueTime, $referenceNow),
            default => null,
        };

        if (
            $nextRunAt !== null
            && $reminder->ends_at !== null
            && $nextRunAt->greaterThan(CarbonImmutable::instance($reminder->ends_at)->setTimezone($timezone))
        ) {
            return null;
        }

        return $nextRunAt !== null ? $this->toAppTimezone($nextRunAt) : null;
    }

    private function nextIntervalRun(Reminder $reminder, CarbonImmutable $currentDueTime, CarbonImmutable $referenceNow): ?CarbonImmutable
    {
        if ($reminder->interval_minutes === null || $reminder->interval_minutes < 1) {
            return null;
        }

        $nextRunAt = $currentDueTime;

        do {
            $nextRunAt = $nextRunAt->addMinutes($reminder->interval_minutes);
        } while ($nextRunAt->lessThanOrEqualTo($referenceNow));

        return $nextRunAt;
    }

    private function nextCronRun(Reminder $reminder, CarbonImmutable $currentDueTime, CarbonImmutable $referenceNow): ?CarbonImmutable
    {
        if ($reminder->cron_expression === null || $reminder->cron_expression === '') {
            return null;
        }

        $cronExpression = new CronExpression($reminder->cron_expression);
        $timezone = $this->reminderTimezone($reminder);

        $nextRunAt = CarbonImmutable::instance(
            $cronExpression->getNextRunDate($currentDueTime, 0, false, $timezone),
        );

        while ($nextRunAt->lessThanOrEqualTo($referenceNow)) {
            $nextRunAt = CarbonImmutable::instance(
                $cronExpression->getNextRunDate($nextRunAt, 0, false, $timezone),
            );
        }

        return $nextRunAt;
    }

    private function deleteDeliveryMessage(ReminderDelivery $delivery, Reminder $reminder): array
    {
        if ($delivery->telegram_message_id === null) {
            return [
                'message_delete_attempted' => false,
                'message_deleted' => false,
            ];
        }

        $chatId = $delivery->chat?->telegram_chat_id
            ?? data_get($delivery->response_payload, 'result.chat.id')
            ?? $this->resolveChatId($reminder);

        try {
            $response = $this->telegramBotClient->deleteMessage($chatId, (int) $delivery->telegram_message_id);

            return [
                'message_delete_attempted' => true,
                'message_deleted' => true,
                'message_delete_response' => $response,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'message_delete_attempted' => true,
                'message_deleted' => false,
                'message_delete_error' => $exception->getMessage(),
            ];
        }
    }

    private function statusResponseTimeoutMinutes(): int
    {
        $timeout = (int) config('services.telegram.status_response_timeout_minutes', 15);

        if ($timeout < 1) {
            return 0;
        }

        return $timeout;
    }

    private function reminderTimezone(Reminder $reminder): string
    {
        $timezone = trim((string) $reminder->timezone);

        return $timezone !== '' ? $timezone : $this->appTimezone();
    }

    private function toAppTimezone(CarbonImmutable $value): CarbonImmutable
    {
        return $value->setTimezone($this->appTimezone());
    }

    private function appTimezone(): string
    {
        return (string) config('app.timezone');
    }

    private function tr(string $key, array $replace = []): string
    {
        return trans($key, $replace, $this->messageLocale());
    }

    private function messageLocale(): string
    {
        return (string) config('services.telegram.message_locale', 'ru');
    }
}
