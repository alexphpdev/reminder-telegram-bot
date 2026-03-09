<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

class TelegramBotClient
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {
    }

    public function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        return $this->request('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
        ], $options));
    }

    public function setWebhook(string $url, array $options = []): array
    {
        return $this->request('setWebhook', array_merge([
            'url' => $url,
        ], $options));
    }

    public function deleteWebhook(bool $dropPendingUpdates = false): array
    {
        return $this->request('deleteWebhook', [
            'drop_pending_updates' => $dropPendingUpdates,
        ]);
    }

    public function deleteMessage(int|string $chatId, int $messageId): array
    {
        return $this->request('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    public function editMessageReplyMarkup(
        int|string $chatId,
        int $messageId,
        array $replyMarkup = ['inline_keyboard' => []],
    ): array {
        return $this->request('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => $replyMarkup,
        ]);
    }

    public function editMessageText(
        int|string $chatId,
        int $messageId,
        string $text,
        array $options = [],
    ): array {
        return $this->request('editMessageText', array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ], $options));
    }

    public function getUpdates(
        ?int $offset = null,
        int $timeout = 30,
        int $limit = 100,
        array $options = [],
    ): array {
        return $this->request(
            'getUpdates',
            array_merge(array_filter([
                'offset' => $offset,
                'timeout' => $timeout,
                'limit' => $limit,
            ], fn (mixed $value): bool => $value !== null), $options),
            timeout: max(10, $timeout + 5),
        );
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): array
    {
        return $this->request('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ], fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    public function request(string $method, array $payload = [], int|float $timeout = 10): array
    {
        $response = $this->client($timeout)->post($method, $payload);

        $response->throw();

        $data = $response->json();

        if (! is_array($data) || ($data['ok'] ?? false) !== true) {
            throw new RuntimeException(sprintf('Telegram API error on method [%s].', $method));
        }

        return $data;
    }

    private function client(int|float $timeout = 10): PendingRequest
    {
        $token = (string) config('services.telegram.bot_token', '');

        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        $apiUrl = rtrim((string) config('services.telegram.api_url', 'https://api.telegram.org'), '/');

        return $this->http
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->retry(2, 200, throw: false)
            ->baseUrl(sprintf('%s/bot%s', $apiUrl, $token));
    }
}
