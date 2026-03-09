<?php

namespace App\Services\Telegram;

use App\Models\Reminder;
use App\Models\ReminderDraft;
use App\Models\ReminderDelivery;
use App\Models\TelegramChat;
use App\Models\TelegramUpdate;
use App\Models\TelegramUser;
use App\Models\WeatherLocation;
use App\Services\Weather\OpenMeteoClient;
use App\Services\Weather\WeatherDailyReportMessageBuilder;
use App\Services\Weather\WeatherForecastParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

class TelegramUpdateProcessor
{
    private const MENU_CREATE_BUTTON = '➕ Добавить напоминание';
    private const MENU_CREATE_BUTTON_LEGACY = '➕ Добавить напоминание';
    private const MENU_MY_REMINDERS_BUTTON = '📋 Мои напоминания';
    private const MENU_WEATHER_BUTTON = '🌤 Погода';
    private const FLOW_CANCEL_BUTTON = 'Отмена';
    private const FLOW_CONFIRM_BUTTON = 'Создать';
    private const WEATHER_CURRENT_QUERY = 'temperature_2m,apparent_temperature,weather_code,wind_speed_10m,wind_direction_10m';
    private const WEATHER_CURRENT_DAILY_QUERY = 'sunrise,sunset';

    public function __construct(
        private readonly TelegramBotClient $telegramBotClient,
        private readonly OpenMeteoClient $openMeteoClient,
        private readonly WeatherForecastParser $weatherForecastParser,
        private readonly WeatherDailyReportMessageBuilder $weatherMessageBuilder,
    ) {
    }

    public function handle(array $payload): void
    {
        $updateId = data_get($payload, 'update_id');

        if (! is_numeric($updateId)) {
            return;
        }

        $chat = $this->upsertChat($this->extractChat($payload));
        $user = $this->upsertUser($this->extractUser($payload));
        $this->syncAdditionalUsers($payload);

        $update = TelegramUpdate::query()->firstOrCreate(
            ['update_id' => (int) $updateId],
            [
                'chat_id' => $chat?->id,
                'user_id' => $user?->id,
                'update_type' => $this->detectUpdateType($payload),
                'message_text' => $this->extractText($payload),
                'payload' => $payload,
                'received_at' => now(),
            ],
        );

        if (! $update->wasRecentlyCreated) {
            return;
        }

        $messageHandled = $this->handleMessageCommand($payload, $chat, $user);

        if (! $messageHandled) {
            $this->handleReminderDraftMessage($payload, $chat, $user);
        }

        $this->handleCallbackQuery($payload['callback_query'] ?? null);

        $update->forceFill([
            'processed_at' => now(),
        ])->save();
    }

    private function handleMessageCommand(array $payload, ?TelegramChat $chat, ?TelegramUser $user): bool
    {
        $text = data_get($payload, 'message.text');

        if (! is_string($text) || $chat === null) {
            return false;
        }

        $command = Str::of((string) Str::before(trim(Str::before($text, ' ')), '@'))
            ->lower()
            ->value();

        $replyToMessageId = data_get($payload, 'message.message_id');
        $text = trim($text);

        if ($command === '/start') {
            $this->telegramBotClient->sendMessage(
                $chat->telegram_chat_id,
                $this->tr('bot.telegram.start_message'),
                [
                    'reply_to_message_id' => $replyToMessageId,
                    'reply_markup' => $this->buildMainMenuKeyboard(),
                ],
            );

            return true;
        }

        if ($command === '/chatid') {
            $message = implode("\n", array_filter([
                $this->tr('bot.telegram.chat_id_line', ['chat_id' => $chat->telegram_chat_id]),
                $user !== null
                    ? $this->tr('bot.telegram.telegram_user_id_line', ['telegram_user_id' => $user->telegram_user_id])
                    : null,
                $user?->id !== null
                    ? $this->tr('bot.telegram.local_user_id_line', ['user_id' => $user->id])
                    : null,
                $this->tr('bot.telegram.local_chat_id_line', ['chat_id' => $chat->id]),
            ]));

            $this->telegramBotClient->sendMessage(
                $chat->telegram_chat_id,
                $message,
                [
                    'reply_to_message_id' => $replyToMessageId,
                ],
            );

            return true;
        }

        if ($command === '/menu') {
            $this->telegramBotClient->sendMessage(
                $chat->telegram_chat_id,
                $this->tr('bot.drafts.menu_hint'),
                [
                    'reply_to_message_id' => $replyToMessageId,
                    'reply_markup' => $this->buildMainMenuKeyboard(),
                    'disable_notification' => true,
                ],
            );

            return true;
        }

        if ($command === '/myreminders' || $text === self::MENU_MY_REMINDERS_BUTTON) {
            if ($user === null) {
                return true;
            }

            $this->sendMyFutureRemindersList($chat, $user, $replyToMessageId);

            return true;
        }

        if ($command === '/weather' || $text === self::MENU_WEATHER_BUTTON) {
            if ($user === null) {
                return true;
            }

            $this->sendWeatherLocationsList($chat, $user, $replyToMessageId);

            return true;
        }

        if ($command === '/once' || in_array($text, [self::MENU_CREATE_BUTTON, self::MENU_CREATE_BUTTON_LEGACY], true)) {
            if ($user === null) {
                return true;
            }

            $this->startReminderDraft($chat, $user, $replyToMessageId);

            return true;
        }

        if ($command === '/cancel') {
            if ($user === null) {
                return true;
            }

            $this->cancelActiveReminderDraft($chat, $user, $replyToMessageId);

            return true;
        }

        return false;
    }

    private function handleCallbackQuery(mixed $callbackQuery): void
    {
        if (! is_array($callbackQuery)) {
            return;
        }

        $callbackQueryId = (string) ($callbackQuery['id'] ?? '');
        $data = (string) ($callbackQuery['data'] ?? '');

        if ($this->handleMyRemindersCallback($callbackQuery, $callbackQueryId, $data)) {
            return;
        }

        if ($this->handleReminderDraftCallback($callbackQuery, $callbackQueryId, $data)) {
            return;
        }

        if ($this->handleWeatherCallback($callbackQuery, $callbackQueryId, $data)) {
            return;
        }

        if (! preg_match('/^delivery:(\d+):(done|later)$/', $data, $matches)) {
            return;
        }

        $delivery = ReminderDelivery::query()
            ->with(['user', 'reminder', 'chat'])
            ->find((int) $matches[1]);

        if ($delivery === null) {
            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.callbacks.delivery_not_found'),
            );

            return;
        }

        if (! $this->canAcknowledgeDelivery($delivery, $callbackQuery)) {
            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.callbacks.not_allowed_for_user'),
            );

            return;
        }

        $status = $matches[2] === 'done'
            ? ReminderDelivery::STATUS_DONE
            : ReminderDelivery::STATUS_NEEDS_ATTENTION;

        $wasSaved = $this->markDeliveryAsAcknowledged($delivery, $status, $callbackQuery);

        if (! $wasSaved) {
            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.callbacks.already_processed'),
            );

            return;
        }

        $delivery->refresh();

        if ($matches[2] === 'later' && $delivery->reminder !== null) {
            $delivery->reminder->forceFill([
                'snooze_until' => now()->addMinutes(30),
                'status' => Reminder::STATUS_ACTIVE,
            ])->save();
        }

        $this->clearInlineButtons($delivery, $callbackQuery);

        $this->telegramBotClient->answerCallbackQuery(
            $callbackQueryId,
            $status === ReminderDelivery::STATUS_DONE
                ? $this->tr('bot.callbacks.done_saved')
                : $this->tr('bot.callbacks.later_saved'),
        );

        if ($status === ReminderDelivery::STATUS_DONE) {
            $this->sendDoneCongratulationMessage($delivery, $callbackQuery);
        }
    }

    private function handleWeatherCallback(array $callbackQuery, string $callbackQueryId, string $data): bool
    {
        if (! preg_match('/^weather:(close|pick):(\d+)(?::(\d+))?(?::(\d+))?$/', $data, $matches)) {
            return false;
        }

        $action = $matches[1];
        $expectedTelegramUserId = (int) $matches[2];
        $thirdValue = is_numeric($matches[3] ?? null) ? (int) $matches[3] : null;
        $fourthValue = is_numeric($matches[4] ?? null) ? (int) $matches[4] : null;
        $locationId = $action === 'pick' ? ($thirdValue ?? 0) : 0;
        $sourceMessageId = $action === 'pick' ? $fourthValue : $thirdValue;
        $actorTelegramUserId = data_get($callbackQuery, 'from.id');

        if (! is_numeric($actorTelegramUserId) || (int) $actorTelegramUserId !== $expectedTelegramUserId) {
            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.weather.callback_only_owner'),
            );

            return true;
        }

        $chatId = data_get($callbackQuery, 'message.chat.id');
        $messageId = data_get($callbackQuery, 'message.message_id');

        if (($chatId === null || $chatId === '') || ! is_numeric($messageId)) {
            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.weather.callback_location_not_found'),
            );

            return true;
        }

        if ($action === 'close') {
            $this->deleteMessageSafely($chatId, (int) $messageId);

            if ($sourceMessageId !== null && $sourceMessageId > 0 && $sourceMessageId !== (int) $messageId) {
                $this->deleteMessageSafely($chatId, $sourceMessageId);
            }

            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.weather.closed'),
            );

            return true;
        }

        if ($locationId < 1) {
            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.weather.callback_location_not_found'),
            );

            return true;
        }

        $location = WeatherLocation::query()->find($locationId);

        if ($location === null) {
            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.weather.callback_location_not_found'),
            );

            return true;
        }

        $this->telegramBotClient->sendMessage(
            $chatId,
            $this->buildCurrentWeatherMessage($location),
            [
                'disable_notification' => true,
                'parse_mode' => 'HTML',
                'reply_markup' => $this->buildWeatherResultKeyboard($expectedTelegramUserId),
            ],
        );

        $this->deleteMessageSafely($chatId, (int) $messageId);

        if ($sourceMessageId !== null && $sourceMessageId > 0 && $sourceMessageId !== (int) $messageId) {
            $this->deleteMessageSafely($chatId, $sourceMessageId);
        }

        $this->telegramBotClient->answerCallbackQuery(
            $callbackQueryId,
            $this->tr('bot.weather.callback_loaded'),
        );

        return true;
    }

    private function sendWeatherLocationsList(TelegramChat $chat, TelegramUser $user, mixed $replyToMessageId = null): void
    {
        $locations = WeatherLocation::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        $sourceMessageId = is_numeric($replyToMessageId) ? (int) $replyToMessageId : null;

        $this->telegramBotClient->sendMessage(
            $chat->telegram_chat_id,
            $locations->isEmpty()
                ? $this->tr('bot.weather.menu_empty')
                : $this->tr('bot.weather.menu_title'),
            [
                'disable_notification' => true,
                'reply_markup' => $this->buildWeatherLocationKeyboard($user, $locations->all(), $sourceMessageId),
            ],
        );
    }

    /**
     * @param array<int, WeatherLocation> $locations
     */
    private function buildWeatherLocationKeyboard(TelegramUser $user, array $locations, ?int $sourceMessageId = null): array
    {
        $keyboard = [];
        $sourceSuffix = $sourceMessageId !== null && $sourceMessageId > 0 ? ':'.$sourceMessageId : '';

        foreach ($locations as $location) {
            $keyboard[] = [[
                'text' => $this->buildWeatherLocationButtonLabel($location),
                'callback_data' => sprintf('weather:pick:%d:%d%s', $user->telegram_user_id, $location->id, $sourceSuffix),
            ]];
        }

        $keyboard[] = [[
            'text' => $this->tr('bot.weather.close_button'),
            'callback_data' => sprintf('weather:close:%d%s', $user->telegram_user_id, $sourceSuffix),
        ]];

        return [
            'inline_keyboard' => $keyboard,
        ];
    }

    private function buildWeatherResultKeyboard(int $telegramUserId): array
    {
        return [
            'inline_keyboard' => [[
                [
                    'text' => $this->tr('bot.weather.close_button'),
                    'callback_data' => sprintf('weather:close:%d', $telegramUserId),
                ],
            ]],
        ];
    }

    private function buildWeatherLocationButtonLabel(WeatherLocation $location): string
    {
        return $location->is_active
            ? $this->tr('bot.weather.location_active', ['name' => $location->name])
            : $this->tr('bot.weather.location_inactive', ['name' => $location->name]);
    }

    private function buildCurrentWeatherMessage(WeatherLocation $location): string
    {
        try {
            $response = $this->openMeteoClient->forecast($location, [
                'forecast_days' => 1,
                'current' => self::WEATHER_CURRENT_QUERY,
                'daily' => self::WEATHER_CURRENT_DAILY_QUERY,
            ]);

            $payload = is_array($response['payload'] ?? null) ? $response['payload'] : [];
            $parsed = $this->weatherForecastParser->parseDailyReportPayload($payload, $location->timezoneOrDefault());

            return $this->weatherMessageBuilder->buildCurrentOnly($location, $parsed);
        } catch (Throwable $exception) {
            report($exception);

            return $this->tr('bot.weather.report_unavailable', [
                'location' => $location->name,
                'date' => CarbonImmutable::now($location->timezoneOrDefault())->format('d.m.Y'),
            ]);
        }
    }

    private function canAcknowledgeDelivery(ReminderDelivery $delivery, array $callbackQuery): bool
    {
        if ($delivery->user_id === null) {
            return true;
        }

        $targetTelegramUserId = $delivery->user?->telegram_user_id;
        $actorTelegramUserId = data_get($callbackQuery, 'from.id');

        if ($targetTelegramUserId === null || ! is_numeric($actorTelegramUserId)) {
            return false;
        }

        return (int) $actorTelegramUserId === (int) $targetTelegramUserId;
    }

    private function markDeliveryAsAcknowledged(ReminderDelivery $delivery, string $status, array $callbackQuery): bool
    {
        $updated = ReminderDelivery::query()
            ->whereKey($delivery->id)
            ->where('delivery_status', ReminderDelivery::STATUS_SENT)
            ->whereNull('acknowledged_at')
            ->update([
                'delivery_status' => $status,
                'acknowledged_at' => now(),
                'status_payload' => $callbackQuery,
            ]);

        return $updated === 1;
    }

    private function clearInlineButtons(ReminderDelivery $delivery, array $callbackQuery): void
    {
        $chatId = data_get($callbackQuery, 'message.chat.id')
            ?? $delivery->chat?->telegram_chat_id
            ?? data_get($delivery->response_payload, 'result.chat.id');
        $messageId = data_get($callbackQuery, 'message.message_id') ?? $delivery->telegram_message_id;

        if (($chatId === null || $chatId === '') || ! is_numeric($messageId)) {
            return;
        }

        try {
            $this->telegramBotClient->editMessageReplyMarkup(
                $chatId,
                (int) $messageId,
                ['inline_keyboard' => []],
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function sendDoneCongratulationMessage(ReminderDelivery $delivery, array $callbackQuery): void
    {
        $chatId = data_get($callbackQuery, 'message.chat.id')
            ?? $delivery->chat?->telegram_chat_id
            ?? data_get($delivery->response_payload, 'result.chat.id');

        if ($chatId === null || $chatId === '') {
            return;
        }

        $reminderText = trim((string) ($delivery->reminder?->message ?? ''));

        if ($reminderText === '') {
            $reminderText = trim((string) $delivery->message_text);
        }

        $text = $this->tr('bot.callbacks.done_chat_congrats_with_message', [
            'message' => e($reminderText),
        ]);

        try {
            $this->telegramBotClient->sendMessage(
                $chatId,
                $text,
                [
                    'disable_notification' => true,
                    'parse_mode' => 'HTML',
                ],
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function handleMyRemindersCallback(array $callbackQuery, string $callbackQueryId, string $data): bool
    {
        if (! preg_match('/^myreminders:(close|back|card|toggle|delete|delete_confirm|delete_cancel):(\d+)(?::(\d+))?(?::(\d+))?$/', $data, $matches)) {
            return false;
        }

        $action = $matches[1];
        $expectedTelegramUserId = (int) $matches[2];
        $thirdValue = is_numeric($matches[3] ?? null) ? (int) $matches[3] : null;
        $fourthValue = is_numeric($matches[4] ?? null) ? (int) $matches[4] : null;
        $actionNeedsReminderId = in_array($action, ['card', 'toggle', 'delete', 'delete_confirm', 'delete_cancel'], true);
        $reminderId = $actionNeedsReminderId ? ($thirdValue ?? 0) : 0;
        $sourceMessageId = $actionNeedsReminderId ? $fourthValue : $thirdValue;

        if (! $this->isMyRemindersOwner($callbackQuery, $callbackQueryId, $expectedTelegramUserId)) {
            return true;
        }

        if ($action === 'close') {
            $chatId = data_get($callbackQuery, 'message.chat.id');
            $messageId = data_get($callbackQuery, 'message.message_id');

            if (($chatId !== null && $chatId !== '') && is_numeric($messageId)) {
                $this->deleteMessageSafely($chatId, (int) $messageId);

                if ($sourceMessageId !== null && $sourceMessageId > 0 && $sourceMessageId !== (int) $messageId) {
                    $this->deleteMessageSafely($chatId, $sourceMessageId);
                }
            }

            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.my_reminders.closed'),
            );

            return true;
        }

        $user = TelegramUser::query()
            ->where('telegram_user_id', $expectedTelegramUserId)
            ->first();

        if ($user === null) {
            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.my_reminders.reminder_not_found'),
            );

            return true;
        }

        if ($action === 'back') {
            $rows = $this->myFutureReminderRows($user);
            $this->editMyRemindersMessage(
                $callbackQuery,
                $this->buildMyRemindersText($rows),
                $this->buildMyRemindersListKeyboard($user, $rows, $sourceMessageId),
            );

            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.my_reminders.list_opened'),
            );

            return true;
        }

        if ($reminderId < 1) {
            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.my_reminders.reminder_not_found'),
            );

            return true;
        }

        $reminder = Reminder::query()
            ->whereKey($reminderId)
            ->where('user_id', $user->id)
            ->whereIn('status', [Reminder::STATUS_ACTIVE, Reminder::STATUS_PAUSED])
            ->first();

        if ($reminder === null) {
            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.my_reminders.reminder_not_found'),
            );

            return true;
        }

        if ($action === 'card') {
            $this->editMyRemindersMessage(
                $callbackQuery,
                $this->buildMyReminderCardText($reminder),
                $this->buildMyRemindersCardKeyboard($user, $reminder, $sourceMessageId),
            );

            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.my_reminders.card_opened'),
            );

            return true;
        }

        if ($action === 'toggle') {
            $nextStatus = $reminder->status === Reminder::STATUS_PAUSED
                ? Reminder::STATUS_ACTIVE
                : Reminder::STATUS_PAUSED;

            $reminder->forceFill([
                'status' => $nextStatus,
            ])->save();
            $reminder->refresh();

            $this->editMyRemindersMessage(
                $callbackQuery,
                $this->buildMyReminderCardText($reminder),
                $this->buildMyRemindersCardKeyboard($user, $reminder, $sourceMessageId),
            );

            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $nextStatus === Reminder::STATUS_PAUSED
                    ? $this->tr('bot.my_reminders.toggled_paused')
                    : $this->tr('bot.my_reminders.toggled_resumed'),
            );

            return true;
        }

        if ($action === 'delete') {
            $this->editMyRemindersMessage(
                $callbackQuery,
                $this->buildMyReminderDeleteConfirmText($reminder),
                $this->buildMyRemindersDeleteConfirmKeyboard($user, $reminder, $sourceMessageId),
            );

            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.my_reminders.delete_confirmation_opened'),
            );

            return true;
        }

        if ($action === 'delete_cancel') {
            $this->editMyRemindersMessage(
                $callbackQuery,
                $this->buildMyReminderCardText($reminder),
                $this->buildMyRemindersCardKeyboard($user, $reminder, $sourceMessageId),
            );

            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.my_reminders.card_opened'),
            );

            return true;
        }

        $reminder->delete();

        $rows = $this->myFutureReminderRows($user);
        $this->editMyRemindersMessage(
            $callbackQuery,
            $this->buildMyRemindersText($rows),
            $this->buildMyRemindersListKeyboard($user, $rows, $sourceMessageId),
        );

        $this->telegramBotClient->answerCallbackQuery(
            $callbackQueryId,
            $this->tr('bot.my_reminders.deleted'),
        );

        return true;
    }

    private function isMyRemindersOwner(array $callbackQuery, string $callbackQueryId, int $expectedTelegramUserId): bool
    {
        $actorTelegramUserId = data_get($callbackQuery, 'from.id');

        if (! is_numeric($actorTelegramUserId) || (int) $actorTelegramUserId !== $expectedTelegramUserId) {
            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.my_reminders.only_owner'),
            );

            return false;
        }

        return true;
    }

    private function sendMyFutureRemindersList(TelegramChat $chat, TelegramUser $user, mixed $replyToMessageId = null): void
    {
        $rows = $this->myFutureReminderRows($user);
        $sourceMessageId = is_numeric($replyToMessageId) ? (int) $replyToMessageId : null;

        $this->telegramBotClient->sendMessage(
            $chat->telegram_chat_id,
            $this->buildMyRemindersText($rows),
            array_filter([
                'disable_notification' => true,
                'parse_mode' => 'HTML',
                'reply_markup' => $this->buildMyRemindersListKeyboard($user, $rows, $sourceMessageId),
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    private function myFutureReminderRows(TelegramUser $user): array
    {
        $now = CarbonImmutable::now((string) config('app.timezone'));

        return Reminder::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [Reminder::STATUS_ACTIVE, Reminder::STATUS_PAUSED])
            ->where(function ($query) use ($now): void {
                $query
                    ->where('next_run_at', '>', $now)
                    ->orWhere('snooze_until', '>', $now);
            })
            ->get()
            ->map(function (Reminder $reminder) use ($now): ?array {
                $scheduledAt = $this->nextReminderMoment($reminder, $now);

                if ($scheduledAt === null) {
                    return null;
                }

                return [
                    'reminder' => $reminder,
                    'scheduled_at' => $scheduledAt,
                ];
            })
            ->filter()
            ->sortBy('scheduled_at')
            ->values()
            ->all();
    }

    private function nextReminderMoment(Reminder $reminder, CarbonImmutable $now): ?CarbonImmutable
    {
        $nextRunAt = $reminder->next_run_at !== null ? CarbonImmutable::instance($reminder->next_run_at) : null;
        $snoozeUntil = $reminder->snooze_until !== null ? CarbonImmutable::instance($reminder->snooze_until) : null;
        $candidates = array_filter(
            [$nextRunAt, $snoozeUntil],
            fn (?CarbonImmutable $value): bool => $value !== null && $value->greaterThan($now),
        );

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        return $candidates[0];
    }

    private function buildMyRemindersText(array $rows): string
    {
        if ($rows === []) {
            return $this->tr('bot.my_reminders.empty');
        }

        $hasPaused = collect($rows)->contains(function (array $row): bool {
            /** @var Reminder $reminder */
            $reminder = $row['reminder'];

            return $reminder->status === Reminder::STATUS_PAUSED;
        });

        $lines = [$this->tr('bot.my_reminders.title')];

        foreach ($rows as $row) {
            /** @var Reminder $reminder */
            $reminder = $row['reminder'];
            /** @var CarbonImmutable $scheduledAt */
            $scheduledAt = $row['scheduled_at'];
            $timezone = trim((string) $reminder->timezone) !== ''
                ? (string) $reminder->timezone
                : (string) config('app.timezone');
            $time = $scheduledAt
                ->setTimezone($timezone)
                ->locale($this->messageLocale())
                ->translatedFormat('d.m.Y H:i');
            $message = e((string) $reminder->message);

            if ($hasPaused) {
                $statusPart = $reminder->status === Reminder::STATUS_PAUSED
                    ? $this->tr('bot.my_reminders.status_paused')
                    : $this->tr('bot.my_reminders.status_active');

                $lines[] = sprintf('• %s | <b>%s</b> — %s', $statusPart, $time, $message);
            } else {
                $lines[] = sprintf('• <b>%s</b> — %s', $time, $message);
            }
        }

        return implode("\n", $lines);
    }

    private function buildMyReminderCardText(Reminder $reminder): string
    {
        $appTimezone = (string) config('app.timezone');
        $now = CarbonImmutable::now($appTimezone);
        $reminderTimezone = trim((string) $reminder->timezone) !== '' ? (string) $reminder->timezone : $appTimezone;
        $nextRunAt = $this->nextReminderMoment($reminder, $now);
        $nextRunAtText = $nextRunAt !== null
            ? $nextRunAt->setTimezone($reminderTimezone)->locale($this->messageLocale())->translatedFormat('d.m.Y H:i')
            : $this->tr('bot.my_reminders.card_time_none');
        $statusLabel = $reminder->status === Reminder::STATUS_PAUSED
            ? $this->tr('bot.my_reminders.status_paused')
            : $this->tr('bot.my_reminders.status_active');
        $lines = [
            $this->tr('bot.my_reminders.card_title'),
            $this->tr('bot.my_reminders.card_text', ['message' => e((string) $reminder->message)]),
            $this->tr('bot.my_reminders.card_next_run', ['time' => $nextRunAtText]),
            $this->tr('bot.my_reminders.card_schedule', ['schedule' => $this->describeReminderSchedule($reminder)]),
            $this->tr('bot.my_reminders.card_timezone', ['timezone' => e($reminderTimezone)]),
            $this->tr('bot.my_reminders.card_status', ['status' => $statusLabel]),
        ];
        $snoozeUntil = $reminder->snooze_until !== null ? CarbonImmutable::instance($reminder->snooze_until) : null;

        if ($snoozeUntil !== null && $snoozeUntil->greaterThan($now)) {
            $lines[] = $this->tr('bot.my_reminders.card_snooze_until', [
                'time' => $snoozeUntil->setTimezone($reminderTimezone)->locale($this->messageLocale())->translatedFormat('d.m.Y H:i'),
            ]);
        }

        return implode("\n", $lines);
    }

    private function describeReminderSchedule(Reminder $reminder): string
    {
        return match ($reminder->schedule_type) {
            Reminder::SCHEDULE_ONCE => $this->tr('bot.my_reminders.schedule_once'),
            Reminder::SCHEDULE_INTERVAL => $reminder->interval_minutes !== null && $reminder->interval_minutes > 0
                ? $this->tr('bot.my_reminders.schedule_interval', ['minutes' => $reminder->interval_minutes])
                : $this->tr('bot.my_reminders.schedule_unknown'),
            Reminder::SCHEDULE_CRON => trim((string) $reminder->cron_expression) !== ''
                ? $this->tr('bot.my_reminders.schedule_cron', ['expression' => e((string) $reminder->cron_expression)])
                : $this->tr('bot.my_reminders.schedule_unknown'),
            default => $this->tr('bot.my_reminders.schedule_unknown'),
        };
    }

    private function editMyRemindersMessage(array $callbackQuery, string $text, array $replyMarkup): void
    {
        $chatId = data_get($callbackQuery, 'message.chat.id');
        $messageId = data_get($callbackQuery, 'message.message_id');

        if (($chatId === null || $chatId === '') || ! is_numeric($messageId)) {
            return;
        }

        try {
            $this->telegramBotClient->editMessageText(
                $chatId,
                (int) $messageId,
                $text,
                [
                    'parse_mode' => 'HTML',
                    'reply_markup' => $replyMarkup,
                ],
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function deleteMessageSafely(int|string $chatId, int $messageId): void
    {
        try {
            $this->telegramBotClient->deleteMessage($chatId, $messageId);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function handleReminderDraftMessage(array $payload, ?TelegramChat $chat, ?TelegramUser $user): void
    {
        if ($chat === null || $user === null) {
            return;
        }

        $text = data_get($payload, 'message.text');
        $messageId = data_get($payload, 'message.message_id');

        if (! is_string($text) || ! is_numeric($messageId)) {
            return;
        }

        $text = trim($text);
        $draft = $this->activeReminderDraft($chat, $user);

        if ($draft === null) {
            return;
        }

        $this->appendTrackedMessageId($draft, (int) $messageId);

        if ($this->isCancelText($text)) {
            $this->finishReminderDraftAsCanceled($draft, $chat->telegram_chat_id);

            return;
        }

        if ($draft->step === ReminderDraft::STEP_AWAITING_TEXT) {
            $payloadData = is_array($draft->payload) ? $draft->payload : [];
            $payloadData['text'] = $text;

            $draft->forceFill([
                'step' => ReminderDraft::STEP_AWAITING_DAY,
                'payload' => $payloadData,
            ])->save();

            $this->sendDraftMessage(
                $draft,
                $chat->telegram_chat_id,
                $this->tr('bot.drafts.ask_day'),
                [
                    'reply_markup' => $this->buildDaySelectionKeyboard($draft),
                ],
            );

            return;
        }

        if ($draft->step === ReminderDraft::STEP_AWAITING_TIME) {
            if (! preg_match('/^(?:[01]?\d|2[0-3]):([0-5]\d)$/', $text)) {
                $this->sendDraftMessage(
                    $draft,
                    $chat->telegram_chat_id,
                    $this->tr('bot.drafts.time_invalid'),
                    [
                        'reply_markup' => $this->buildCancelKeyboard($draft),
                    ],
                );

                return;
            }

            $payloadData = is_array($draft->payload) ? $draft->payload : [];
            $selectedDate = (string) ($payloadData['date'] ?? '');
            $timezone = $this->draftTimezone($draft->targetUser);
            $time = $this->normalizeTime($text);
            $scheduledAt = $this->buildScheduledDateTime($selectedDate, $time, $timezone);

            if ($scheduledAt === null) {
                $this->sendDraftMessage(
                    $draft,
                    $chat->telegram_chat_id,
                    $this->tr('bot.drafts.choose_day_first'),
                    [
                        'reply_markup' => $this->buildDaySelectionKeyboard($draft),
                    ],
                );

                return;
            }

            if ($scheduledAt->lessThanOrEqualTo(CarbonImmutable::now($timezone))) {
                $this->sendDraftMessage(
                    $draft,
                    $chat->telegram_chat_id,
                    $this->tr('bot.drafts.time_past', ['timezone' => $timezone]),
                    [
                        'reply_markup' => $this->buildCancelKeyboard($draft),
                    ],
                );

                return;
            }

            $payloadData['time'] = $time;

            $draft->forceFill([
                'step' => ReminderDraft::STEP_AWAITING_STATUS_MODE,
                'payload' => $payloadData,
            ])->save();

            $this->sendDraftMessage(
                $draft,
                $chat->telegram_chat_id,
                $this->tr('bot.drafts.ask_status_mode'),
                [
                    'reply_markup' => $this->buildStatusModeKeyboard($draft),
                ],
            );

            return;
        }

        if ($draft->step === ReminderDraft::STEP_AWAITING_DAY) {
            $this->sendDraftMessage(
                $draft,
                $chat->telegram_chat_id,
                $this->tr('bot.drafts.ask_day'),
                [
                    'reply_markup' => $this->buildDaySelectionKeyboard($draft),
                ],
            );

            return;
        }

        if ($draft->step === ReminderDraft::STEP_AWAITING_STATUS_MODE) {
            $this->sendDraftMessage(
                $draft,
                $chat->telegram_chat_id,
                $this->tr('bot.drafts.use_status_mode_buttons'),
                [
                    'reply_markup' => $this->buildStatusModeKeyboard($draft),
                ],
            );

            return;
        }

        if ($draft->step === ReminderDraft::STEP_AWAITING_CONFIRM) {
            $this->sendDraftMessage(
                $draft,
                $chat->telegram_chat_id,
                $this->tr('bot.drafts.use_confirm_buttons'),
                [
                    'reply_markup' => $this->buildConfirmKeyboard($draft),
                ],
            );
        }
    }

    private function handleReminderDraftCallback(array $callbackQuery, string $callbackQueryId, string $data): bool
    {
        if (! preg_match('/^draft:(\d+):(cancel|confirm|day:(\d{4}-\d{2}-\d{2})|mode:(ask|notify))$/', $data, $matches)) {
            return false;
        }

        $draft = ReminderDraft::query()
            ->with(['chat', 'initiatorUser', 'targetUser'])
            ->find((int) $matches[1]);

        if ($draft === null || $draft->status !== ReminderDraft::STATUS_ACTIVE || $draft->chat === null) {
            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.drafts.callback_draft_not_found'),
            );

            return true;
        }

        $actorTelegramUserId = data_get($callbackQuery, 'from.id');
        $initiatorTelegramUserId = $draft->initiatorUser?->telegram_user_id;

        if ($initiatorTelegramUserId === null || ! is_numeric($actorTelegramUserId) || (int) $actorTelegramUserId !== (int) $initiatorTelegramUserId) {
            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.drafts.callback_only_initiator'),
            );

            return true;
        }

        $action = $matches[2];

        if ($action === 'cancel') {
            $this->finishReminderDraftAsCanceled($draft, $draft->chat->telegram_chat_id);
            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.drafts.cancelled'),
            );

            return true;
        }

        if (str_starts_with($action, 'day:')) {
            if ($draft->step !== ReminderDraft::STEP_AWAITING_DAY) {
                $this->telegramBotClient->answerCallbackQuery(
                    $callbackQueryId,
                    $this->tr('bot.drafts.callback_wrong_step'),
                );

                return true;
            }

            $payloadData = is_array($draft->payload) ? $draft->payload : [];
            $payloadData['date'] = $matches[3];

            $draft->forceFill([
                'step' => ReminderDraft::STEP_AWAITING_TIME,
                'payload' => $payloadData,
            ])->save();

            $this->sendDraftMessage(
                $draft,
                $draft->chat->telegram_chat_id,
                $this->tr('bot.drafts.ask_time', ['timezone' => $this->draftTimezone($draft->targetUser)]),
                [
                    'reply_markup' => $this->buildCancelKeyboard($draft),
                ],
            );

            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.drafts.callback_day_saved'),
            );

            return true;
        }

        if (str_starts_with($action, 'mode:')) {
            if ($draft->step !== ReminderDraft::STEP_AWAITING_STATUS_MODE) {
                $this->telegramBotClient->answerCallbackQuery(
                    $callbackQueryId,
                    $this->tr('bot.drafts.callback_wrong_step'),
                );

                return true;
            }

            $payloadData = is_array($draft->payload) ? $draft->payload : [];
            $payloadData['ask_status'] = $matches[4] === 'ask';
            $timezone = $this->draftTimezone($draft->targetUser);
            $scheduledAt = $this->buildScheduledDateTime(
                (string) ($payloadData['date'] ?? ''),
                (string) ($payloadData['time'] ?? ''),
                $timezone,
            );

            if ($scheduledAt === null || $scheduledAt->lessThanOrEqualTo(CarbonImmutable::now($timezone))) {
                $draft->forceFill([
                    'step' => ReminderDraft::STEP_AWAITING_TIME,
                    'payload' => $payloadData,
                ])->save();

                $this->sendDraftMessage(
                    $draft,
                    $draft->chat->telegram_chat_id,
                    $this->tr('bot.drafts.time_past', ['timezone' => $timezone]),
                    [
                        'reply_markup' => $this->buildCancelKeyboard($draft),
                    ],
                );

                $this->telegramBotClient->answerCallbackQuery(
                    $callbackQueryId,
                    $this->tr('bot.drafts.callback_wrong_step'),
                );

                return true;
            }

            $draft->forceFill([
                'step' => ReminderDraft::STEP_AWAITING_CONFIRM,
                'payload' => $payloadData,
            ])->save();

            $this->sendDraftMessage(
                $draft,
                $draft->chat->telegram_chat_id,
                $this->buildDraftConfirmationMessage($draft, $scheduledAt, $timezone),
                [
                    'parse_mode' => 'HTML',
                    'reply_markup' => $this->buildConfirmKeyboard($draft),
                ],
            );

            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.drafts.callback_mode_saved'),
            );

            return true;
        }

        if ($draft->step !== ReminderDraft::STEP_AWAITING_CONFIRM) {
            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.drafts.callback_wrong_step'),
            );

            return true;
        }

        $reminder = $this->createReminderFromDraft($draft);

        if ($reminder === null) {
            $draft->forceFill([
                'step' => ReminderDraft::STEP_AWAITING_TIME,
            ])->save();

            $this->sendDraftMessage(
                $draft,
                $draft->chat->telegram_chat_id,
                $this->tr('bot.drafts.time_past', ['timezone' => $this->draftTimezone($draft->targetUser)]),
                [
                    'reply_markup' => $this->buildCancelKeyboard($draft),
                ],
            );

            $this->telegramBotClient->answerCallbackQuery(
                $callbackQueryId,
                $this->tr('bot.drafts.callback_wrong_step'),
            );

            return true;
        }

        $this->finishReminderDraftAsCompleted($draft, $reminder, $draft->chat->telegram_chat_id);

        $this->telegramBotClient->answerCallbackQuery(
            $callbackQueryId,
            $this->tr('bot.drafts.callback_created'),
        );

        return true;
    }

    private function startReminderDraft(TelegramChat $chat, TelegramUser $user, mixed $sourceMessageId = null): void
    {
        $activeDraft = $this->activeReminderDraft($chat, $user);

        if ($activeDraft !== null) {
            $this->sendDraftMessage(
                $activeDraft,
                $chat->telegram_chat_id,
                $this->tr('bot.drafts.already_active'),
                [
                    'reply_markup' => $this->buildCancelKeyboard($activeDraft),
                ],
            );

            return;
        }

        $draft = ReminderDraft::query()->create([
            'chat_id' => $chat->id,
            'initiator_user_id' => $user->id,
            'target_user_id' => $user->id,
            'status' => ReminderDraft::STATUS_ACTIVE,
            'step' => ReminderDraft::STEP_AWAITING_TEXT,
            'payload' => [
                'timezone' => $user->timezoneOrDefault(),
            ],
            'tracked_message_ids' => [],
        ]);

        if (is_numeric($sourceMessageId)) {
            $this->appendTrackedMessageId($draft, (int) $sourceMessageId);
        }

        $this->sendDraftMessage(
            $draft,
            $chat->telegram_chat_id,
            $this->tr('bot.drafts.ask_text'),
            [
                'reply_markup' => $this->buildCancelKeyboard($draft),
            ],
        );
    }

    private function cancelActiveReminderDraft(TelegramChat $chat, TelegramUser $user, mixed $sourceMessageId = null): void
    {
        $draft = $this->activeReminderDraft($chat, $user);

        if ($draft === null) {
            $this->telegramBotClient->sendMessage(
                $chat->telegram_chat_id,
                $this->tr('bot.drafts.no_active'),
                [
                    'disable_notification' => true,
                ],
            );

            return;
        }

        if (is_numeric($sourceMessageId)) {
            $this->appendTrackedMessageId($draft, (int) $sourceMessageId);
        }

        $this->finishReminderDraftAsCanceled($draft, $chat->telegram_chat_id);
    }

    private function activeReminderDraft(TelegramChat $chat, TelegramUser $user): ?ReminderDraft
    {
        return ReminderDraft::query()
            ->with(['chat', 'initiatorUser', 'targetUser'])
            ->where('chat_id', $chat->id)
            ->where('initiator_user_id', $user->id)
            ->where('status', ReminderDraft::STATUS_ACTIVE)
            ->latest('id')
            ->first();
    }

    private function appendTrackedMessageId(ReminderDraft $draft, int $messageId): void
    {
        if ($messageId < 1) {
            return;
        }

        $trackedMessageIds = is_array($draft->tracked_message_ids) ? $draft->tracked_message_ids : [];

        if (in_array($messageId, $trackedMessageIds, true)) {
            return;
        }

        $trackedMessageIds[] = $messageId;

        $draft->forceFill([
            'tracked_message_ids' => $trackedMessageIds,
        ])->save();
    }

    private function sendDraftMessage(ReminderDraft $draft, int|string $chatId, string $text, array $options = []): void
    {
        $response = $this->telegramBotClient->sendMessage(
            $chatId,
            $text,
            array_merge([
                'disable_notification' => true,
            ], $options),
        );

        $messageId = data_get($response, 'result.message_id');

        if (is_numeric($messageId)) {
            $this->appendTrackedMessageId($draft, (int) $messageId);
        }
    }

    private function buildMainMenuKeyboard(): array
    {
        return [
            'keyboard' => [
                [
                    [
                        'text' => self::MENU_CREATE_BUTTON,
                    ],
                    [
                        'text' => self::MENU_MY_REMINDERS_BUTTON,
                    ],
                ],
                [
                    [
                        'text' => self::MENU_WEATHER_BUTTON,
                    ],
                ],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    private function buildMyRemindersListKeyboard(TelegramUser $user, array $rows, ?int $sourceMessageId = null): array
    {
        $keyboard = [];
        $sourceSuffix = $this->myRemindersSourceSuffix($sourceMessageId);

        foreach ($rows as $row) {
            /** @var Reminder $reminder */
            $reminder = $row['reminder'];
            /** @var CarbonImmutable $scheduledAt */
            $scheduledAt = $row['scheduled_at'];

            $keyboard[] = [[
                'text' => $this->buildMyReminderListButtonLabel($reminder, $scheduledAt),
                'callback_data' => sprintf('myreminders:card:%d:%d%s', $user->telegram_user_id, $reminder->id, $sourceSuffix),
            ]];
        }

        $keyboard[] = [[
            'text' => $this->tr('bot.my_reminders.close_button'),
            'callback_data' => sprintf('myreminders:close:%d%s', $user->telegram_user_id, $sourceSuffix),
        ]];

        return [
            'inline_keyboard' => $keyboard,
        ];
    }

    private function buildMyRemindersCardKeyboard(TelegramUser $user, Reminder $reminder, ?int $sourceMessageId = null): array
    {
        $toggleButtonText = $reminder->status === Reminder::STATUS_PAUSED
            ? $this->tr('bot.my_reminders.resume_button')
            : $this->tr('bot.my_reminders.pause_button');
        $sourceSuffix = $this->myRemindersSourceSuffix($sourceMessageId);

        return [
            'inline_keyboard' => [
                [
                    [
                        'text' => $toggleButtonText,
                        'callback_data' => sprintf('myreminders:toggle:%d:%d%s', $user->telegram_user_id, $reminder->id, $sourceSuffix),
                    ],
                    [
                        'text' => $this->tr('bot.my_reminders.delete_button'),
                        'callback_data' => sprintf('myreminders:delete:%d:%d%s', $user->telegram_user_id, $reminder->id, $sourceSuffix),
                    ],
                ],
                [
                    [
                        'text' => $this->tr('bot.my_reminders.back_button'),
                        'callback_data' => sprintf('myreminders:back:%d%s', $user->telegram_user_id, $sourceSuffix),
                    ],
                    [
                        'text' => $this->tr('bot.my_reminders.close_button'),
                        'callback_data' => sprintf('myreminders:close:%d%s', $user->telegram_user_id, $sourceSuffix),
                    ],
                ],
            ],
        ];
    }

    private function buildMyReminderDeleteConfirmText(Reminder $reminder): string
    {
        return implode("\n", [
            $this->tr('bot.my_reminders.delete_confirm_title'),
            $this->tr('bot.my_reminders.delete_confirm_text', ['message' => e((string) $reminder->message)]),
            $this->tr('bot.my_reminders.delete_confirm_hint'),
        ]);
    }

    private function buildMyRemindersDeleteConfirmKeyboard(TelegramUser $user, Reminder $reminder, ?int $sourceMessageId = null): array
    {
        $sourceSuffix = $this->myRemindersSourceSuffix($sourceMessageId);

        return [
            'inline_keyboard' => [
                [[
                    'text' => $this->tr('bot.my_reminders.confirm_delete_button'),
                    'callback_data' => sprintf('myreminders:delete_confirm:%d:%d%s', $user->telegram_user_id, $reminder->id, $sourceSuffix),
                ]],
                [[
                    'text' => $this->tr('bot.my_reminders.cancel_delete_button'),
                    'callback_data' => sprintf('myreminders:delete_cancel:%d:%d%s', $user->telegram_user_id, $reminder->id, $sourceSuffix),
                ]],
                [[
                    'text' => $this->tr('bot.my_reminders.close_button'),
                    'callback_data' => sprintf('myreminders:close:%d%s', $user->telegram_user_id, $sourceSuffix),
                ]],
            ],
        ];
    }

    private function myRemindersSourceSuffix(?int $sourceMessageId): string
    {
        if ($sourceMessageId === null || $sourceMessageId < 1) {
            return '';
        }

        return ':'.$sourceMessageId;
    }

    private function buildMyReminderListButtonLabel(Reminder $reminder, CarbonImmutable $scheduledAt): string
    {
        $timezone = trim((string) $reminder->timezone) !== ''
            ? (string) $reminder->timezone
            : (string) config('app.timezone');
        $time = $scheduledAt
            ->setTimezone($timezone)
            ->locale($this->messageLocale())
            ->translatedFormat('d.m H:i');
        $normalizedMessage = preg_replace('/\s+/u', ' ', strip_tags((string) $reminder->message));
        $message = trim((string) $normalizedMessage);

        if ($message === '') {
            return $time;
        }

        return sprintf('%s • %s', $time, Str::limit($message, 38));
    }

    private function buildCancelKeyboard(ReminderDraft $draft): array
    {
        return [
            'inline_keyboard' => [[
                [
                    'text' => self::FLOW_CANCEL_BUTTON,
                    'callback_data' => sprintf('draft:%d:cancel', $draft->id),
                ],
            ]],
        ];
    }

    private function buildConfirmKeyboard(ReminderDraft $draft): array
    {
        return [
            'inline_keyboard' => [[
                [
                    'text' => self::FLOW_CONFIRM_BUTTON,
                    'callback_data' => sprintf('draft:%d:confirm', $draft->id),
                ],
                [
                    'text' => self::FLOW_CANCEL_BUTTON,
                    'callback_data' => sprintf('draft:%d:cancel', $draft->id),
                ],
            ]],
        ];
    }

    private function buildStatusModeKeyboard(ReminderDraft $draft): array
    {
        return [
            'inline_keyboard' => [
                [
                    [
                        'text' => $this->tr('bot.drafts.status_mode_button_ask'),
                        'callback_data' => sprintf('draft:%d:mode:ask', $draft->id),
                    ],
                    [
                        'text' => $this->tr('bot.drafts.status_mode_button_notify'),
                        'callback_data' => sprintf('draft:%d:mode:notify', $draft->id),
                    ],
                ],
                [[
                    'text' => self::FLOW_CANCEL_BUTTON,
                    'callback_data' => sprintf('draft:%d:cancel', $draft->id),
                ]],
            ],
        ];
    }

    private function buildDaySelectionKeyboard(ReminderDraft $draft): array
    {
        $timezone = $this->draftTimezone($draft->targetUser);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $buttons = [];

        for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
            $date = $today->addDays($dayOffset);

            $buttons[] = [
                'text' => $this->labelForDayButton($date, $dayOffset),
                'callback_data' => sprintf('draft:%d:day:%s', $draft->id, $date->format('Y-m-d')),
            ];
        }

        $rows = array_chunk($buttons, 2);
        $rows[] = [[
            'text' => self::FLOW_CANCEL_BUTTON,
            'callback_data' => sprintf('draft:%d:cancel', $draft->id),
        ]];

        return [
            'inline_keyboard' => $rows,
        ];
    }

    private function labelForDayButton(CarbonImmutable $date, int $dayOffset): string
    {
        if ($dayOffset === 0) {
            return $this->tr('bot.drafts.day_today');
        }

        if ($dayOffset === 1) {
            return $this->tr('bot.drafts.day_tomorrow');
        }

        return $date->locale($this->messageLocale())->translatedFormat('j F');
    }

    private function normalizeTime(string $value): string
    {
        [$hours, $minutes] = explode(':', $value);

        return sprintf('%02d:%02d', (int) $hours, (int) $minutes);
    }

    private function buildScheduledDateTime(string $date, string $time, string $timezone): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d H:i', sprintf('%s %s', $date, $time), $timezone);
        } catch (Throwable) {
            return null;
        }
    }

    private function draftTimezone(?TelegramUser $user): string
    {
        if ($user !== null) {
            return $user->timezoneOrDefault();
        }

        return 'UTC';
    }

    private function buildDraftConfirmationMessage(ReminderDraft $draft, CarbonImmutable $scheduledAt, string $timezone): string
    {
        $payloadData = is_array($draft->payload) ? $draft->payload : [];
        $text = (string) ($payloadData['text'] ?? '');
        $targetMention = $draft->targetUser?->telegramMention() ?? $draft->initiatorUser?->telegramMention() ?? $this->tr('bot.drafts.target_default');
        $formattedDate = $scheduledAt->locale($this->messageLocale())->translatedFormat('d.m.Y H:i');
        $askStatus = $this->draftAskStatus($payloadData);

        return implode("\n", [
            $this->tr('bot.drafts.confirm_title'),
            $this->tr('bot.drafts.summary_target', ['target' => $targetMention]),
            $this->tr('bot.drafts.summary_time', ['time' => $formattedDate, 'timezone' => $timezone]),
            $this->tr('bot.drafts.summary_status_mode', [
                'mode' => $askStatus
                    ? $this->tr('bot.drafts.summary_status_mode_ask')
                    : $this->tr('bot.drafts.summary_status_mode_notify'),
            ]),
            $this->tr('bot.drafts.summary_text', ['text' => e($text)]),
        ]);
    }

    private function createReminderFromDraft(ReminderDraft $draft): ?Reminder
    {
        $payloadData = is_array($draft->payload) ? $draft->payload : [];
        $text = trim((string) ($payloadData['text'] ?? ''));
        $date = (string) ($payloadData['date'] ?? '');
        $time = (string) ($payloadData['time'] ?? '');
        $askStatus = $this->draftAskStatus($payloadData);
        $targetUser = $draft->targetUser;
        $timezone = $this->draftTimezone($targetUser);

        if ($text === '' || $date === '' || $time === '' || $draft->chat === null) {
            return null;
        }

        $scheduledAt = $this->buildScheduledDateTime($date, $time, $timezone);

        if ($scheduledAt === null || $scheduledAt->lessThanOrEqualTo(CarbonImmutable::now($timezone))) {
            return null;
        }

        $nextRunAt = $scheduledAt->setTimezone((string) config('app.timezone'));

        return Reminder::query()->create([
            'title' => Str::limit($text, 60),
            'message' => $text,
            'chat_id' => $draft->chat_id,
            'user_id' => $draft->target_user_id,
            'status' => Reminder::STATUS_ACTIVE,
            'schedule_type' => Reminder::SCHEDULE_ONCE,
            'timezone' => $timezone,
            'next_run_at' => $nextRunAt,
            'ask_status' => $askStatus,
            'meta' => [
                'source' => 'draft',
                'draft_id' => $draft->id,
                'initiator_user_id' => $draft->initiator_user_id,
            ],
        ]);
    }

    private function finishReminderDraftAsCompleted(ReminderDraft $draft, Reminder $reminder, int|string $chatId): void
    {
        $payloadData = is_array($draft->payload) ? $draft->payload : [];
        $timezone = $this->draftTimezone($draft->targetUser);
        $scheduledAt = $this->buildScheduledDateTime(
            (string) ($payloadData['date'] ?? ''),
            (string) ($payloadData['time'] ?? ''),
            $timezone,
        );
        $formattedDate = $scheduledAt?->locale($this->messageLocale())->translatedFormat('d.m.Y H:i') ?? '-';
        $askStatus = $this->draftAskStatus($payloadData);
        $targetMention = $draft->targetUser?->telegramMention()
            ?? $draft->initiatorUser?->telegramMention()
            ?? $this->tr('bot.drafts.target_default');

        $this->cleanupReminderDraftMessages($draft, $chatId);

        $draft->forceFill([
            'status' => ReminderDraft::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();

        $this->telegramBotClient->sendMessage(
            $chatId,
            implode("\n", [
                $this->tr('bot.drafts.created_title', ['id' => $reminder->id]),
                $this->tr('bot.drafts.summary_target', ['target' => $targetMention]),
                $this->tr('bot.drafts.summary_time', ['time' => $formattedDate, 'timezone' => $timezone]),
                $this->tr('bot.drafts.summary_status_mode', [
                    'mode' => $askStatus
                        ? $this->tr('bot.drafts.summary_status_mode_ask')
                        : $this->tr('bot.drafts.summary_status_mode_notify'),
                ]),
                $this->tr('bot.drafts.summary_text', ['text' => e((string) ($payloadData['text'] ?? ''))]),
            ]),
            [
                'disable_notification' => true,
                'parse_mode' => 'HTML',
            ],
        );
    }

    private function finishReminderDraftAsCanceled(ReminderDraft $draft, int|string $chatId): void
    {
        $this->cleanupReminderDraftMessages($draft, $chatId);

        $draft->forceFill([
            'status' => ReminderDraft::STATUS_CANCELED,
            'completed_at' => now(),
        ])->save();
    }

    private function cleanupReminderDraftMessages(ReminderDraft $draft, int|string $chatId): void
    {
        $trackedMessageIds = is_array($draft->tracked_message_ids) ? $draft->tracked_message_ids : [];

        foreach (array_unique($trackedMessageIds) as $messageId) {
            if (! is_numeric($messageId) || (int) $messageId < 1) {
                continue;
            }

            try {
                $this->telegramBotClient->deleteMessage($chatId, (int) $messageId);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function isCancelText(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));

        return in_array($normalized, [
            '/cancel',
            mb_strtolower(self::FLOW_CANCEL_BUTTON),
        ], true);
    }

    /**
     * @param array<string, mixed> $payloadData
     */
    private function draftAskStatus(array $payloadData): bool
    {
        $value = $payloadData['ask_status'] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = mb_strtolower(trim($value));

            return in_array($normalized, ['1', 'true', 'yes', 'ask', 'confirm'], true);
        }

        return true;
    }

    private function upsertChat(?array $chatPayload): ?TelegramChat
    {
        if ($chatPayload === null || ! isset($chatPayload['id'])) {
            return null;
        }

        $chat = TelegramChat::query()->updateOrCreate(
            ['telegram_chat_id' => (int) $chatPayload['id']],
            [
                'type' => (string) ($chatPayload['type'] ?? 'private'),
                'title' => $chatPayload['title'] ?? null,
                'username' => $chatPayload['username'] ?? null,
                'is_active' => true,
                'meta' => $chatPayload,
            ],
        );

        $configuredPrimaryChatId = (string) config('services.telegram.primary_chat_id', '');
        $shouldBePrimary = false;

        if ($configuredPrimaryChatId !== '') {
            $shouldBePrimary = (string) $chat->telegram_chat_id === $configuredPrimaryChatId;
        } elseif (
            in_array($chat->type, ['group', 'supergroup'], true)
            && ! TelegramChat::query()->where('is_primary', true)->whereKeyNot($chat->id)->exists()
        ) {
            $shouldBePrimary = true;
        }

        if ($shouldBePrimary) {
            TelegramChat::query()
                ->where('id', '!=', $chat->id)
                ->update(['is_primary' => false]);

            if (! $chat->is_primary) {
                $chat->forceFill(['is_primary' => true])->save();
            }
        }

        return $chat;
    }

    private function upsertUser(?array $userPayload): ?TelegramUser
    {
        if ($userPayload === null || ! isset($userPayload['id'])) {
            return null;
        }

        return TelegramUser::query()->updateOrCreate(
            ['telegram_user_id' => (int) $userPayload['id']],
            [
                'username' => $userPayload['username'] ?? null,
                'first_name' => $userPayload['first_name'] ?? null,
                'last_name' => $userPayload['last_name'] ?? null,
                'language_code' => $userPayload['language_code'] ?? null,
                'is_bot' => (bool) ($userPayload['is_bot'] ?? false),
                'is_active' => true,
                'meta' => $userPayload,
            ],
        );
    }

    private function detectUpdateType(array $payload): string
    {
        foreach (array_keys($payload) as $key) {
            if ($key !== 'update_id') {
                return $key;
            }
        }

        return 'unknown';
    }

    private function extractText(array $payload): ?string
    {
        $text = data_get($payload, 'message.text')
            ?? data_get($payload, 'edited_message.text')
            ?? data_get($payload, 'callback_query.data')
            ?? data_get($payload, 'my_chat_member.new_chat_member.status')
            ?? data_get($payload, 'chat_member.new_chat_member.status');

        return is_string($text) ? $text : null;
    }

    private function extractChat(array $payload): ?array
    {
        $chat = data_get($payload, 'message.chat')
            ?? data_get($payload, 'edited_message.chat')
            ?? data_get($payload, 'callback_query.message.chat')
            ?? data_get($payload, 'my_chat_member.chat')
            ?? data_get($payload, 'chat_member.chat');

        return is_array($chat) ? $chat : null;
    }

    private function extractUser(array $payload): ?array
    {
        $user = data_get($payload, 'message.from')
            ?? data_get($payload, 'edited_message.from')
            ?? data_get($payload, 'callback_query.from')
            ?? data_get($payload, 'my_chat_member.from')
            ?? data_get($payload, 'chat_member.from');

        return is_array($user) ? $user : null;
    }

    private function syncAdditionalUsers(array $payload): void
    {
        $users = [
            data_get($payload, 'my_chat_member.old_chat_member.user'),
            data_get($payload, 'my_chat_member.new_chat_member.user'),
            data_get($payload, 'chat_member.old_chat_member.user'),
            data_get($payload, 'chat_member.new_chat_member.user'),
        ];

        foreach ($users as $userPayload) {
            if (is_array($userPayload)) {
                $this->upsertUser($userPayload);
            }
        }
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
