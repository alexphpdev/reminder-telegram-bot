<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramBotClient;
use Illuminate\Console\Command;

class TelegramSetWebhookCommand extends Command
{
    protected $signature = 'telegram:set-webhook {url? : Public HTTPS webhook URL}';

    protected $description = 'Register the Telegram webhook for this application';

    public function handle(TelegramBotClient $telegramBotClient): int
    {
        $url = (string) ($this->argument('url')
            ?: config('services.telegram.webhook_url')
            ?: route('telegram.webhook'));

        if ($url === '') {
            $this->error('Webhook URL is empty. Set TELEGRAM_WEBHOOK_URL or pass the URL as an argument.');

            return self::FAILURE;
        }

        $payload = array_filter([
            'secret_token' => config('services.telegram.webhook_secret'),
            'allowed_updates' => ['message', 'callback_query', 'my_chat_member', 'chat_member'],
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $response = $telegramBotClient->setWebhook($url, $payload);

        $this->info(sprintf('Telegram webhook registered: %s', $url));
        $this->line(json_encode($response['result'] ?? $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
