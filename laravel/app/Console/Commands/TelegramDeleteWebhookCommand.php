<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramBotClient;
use Illuminate\Console\Command;

class TelegramDeleteWebhookCommand extends Command
{
    protected $signature = 'telegram:delete-webhook {--drop-pending : Delete pending Telegram updates too}';

    protected $description = 'Delete the Telegram webhook';

    public function handle(TelegramBotClient $telegramBotClient): int
    {
        $response = $telegramBotClient->deleteWebhook(
            dropPendingUpdates: (bool) $this->option('drop-pending'),
        );

        $this->info('Telegram webhook deleted.');
        $this->line(json_encode($response['result'] ?? $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
