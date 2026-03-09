<?php

namespace App\Console\Commands;

use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Console\Command;
use Throwable;

class TelegramPollUpdatesCommand extends Command
{
    protected $signature = 'telegram:poll-updates
        {--once : Fetch one batch and exit}
        {--timeout=30 : Long polling timeout in seconds}
        {--limit=100 : Maximum updates per request}
        {--sleep=1 : Delay in seconds after an error}
        {--take-over : Disable webhook before polling}';

    protected $description = 'Poll Telegram updates with getUpdates for local development';

    public function handle(
        TelegramBotClient $telegramBotClient,
        TelegramUpdateProcessor $telegramUpdateProcessor,
    ): int {
        $timeout = max(0, (int) $this->option('timeout'));
        $limit = max(1, min(100, (int) $this->option('limit')));
        $sleep = max(1, (int) $this->option('sleep'));
        $once = (bool) $this->option('once');

        if ((bool) $this->option('take-over')) {
            $telegramBotClient->deleteWebhook(dropPendingUpdates: false);
            $this->components->info('Webhook disabled. Polling mode is active.');
        }

        $this->components->info('Polling Telegram updates...');

        do {
            try {
                $response = $telegramBotClient->getUpdates(
                    offset: $this->nextOffset(),
                    timeout: $timeout,
                    limit: $limit,
                    options: [
                        'allowed_updates' => ['message', 'callback_query', 'my_chat_member', 'chat_member'],
                    ],
                );

                $updates = $response['result'] ?? [];

                foreach ($updates as $update) {
                    if (is_array($update)) {
                        $telegramUpdateProcessor->handle($update);
                    }
                }

                if ($once) {
                    $this->components->info(sprintf('Fetched %d update(s).', count($updates)));

                    return self::SUCCESS;
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->components->error($exception->getMessage());
                sleep($sleep);
            }
        } while (true);
    }

    private function nextOffset(): int
    {
        return ((int) TelegramUpdate::query()->max('update_id')) + 1;
    }
}
