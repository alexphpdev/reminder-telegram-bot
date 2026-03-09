<?php

namespace App\Console\Commands;

use App\Services\Reminders\ReminderDispatcher;
use Illuminate\Console\Command;

class RemindersDispatchDueCommand extends Command
{
    protected $signature = 'reminders:dispatch-due';

    protected $description = 'Send all due reminders to Telegram';

    public function handle(ReminderDispatcher $reminderDispatcher): int
    {
        $processed = $reminderDispatcher->dispatchDueReminders();

        $this->info(sprintf('Processed %d due reminder(s).', $processed));

        return self::SUCCESS;
    }
}
