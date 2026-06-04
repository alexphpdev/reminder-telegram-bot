<?php

namespace App\Services\Reminders;

use App\Models\Reminder;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Throwable;

class ReminderScheduleService
{
    public function parseLocalDateTime(?string $value, string $timezone): ?CarbonImmutable
    {
        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d\TH:i', $normalized, $timezone);
        } catch (Throwable) {
            return null;
        }
    }

    public function toAppTimezone(CarbonImmutable $value): CarbonImmutable
    {
        return $value->setTimezone($this->appTimezone());
    }

    public function appNow(?CarbonImmutable $now = null): CarbonImmutable
    {
        return $this->toAppTimezone($now ?? CarbonImmutable::now($this->appTimezone()));
    }

    public function initialIntervalRunAt(
        CarbonImmutable $startsAtLocal,
        int $intervalMinutes,
        ?CarbonImmutable $now = null,
    ): ?CarbonImmutable {
        if ($intervalMinutes < 1) {
            return null;
        }

        $referenceNow = $this->appNow($now)->setTimezone($startsAtLocal->getTimezone()->getName());
        $nextRunAt = $startsAtLocal;

        while ($nextRunAt->lessThanOrEqualTo($referenceNow)) {
            $nextRunAt = $nextRunAt->addMinutes($intervalMinutes);
        }

        return $this->toAppTimezone($nextRunAt);
    }

    public function initialCronRunAt(
        ?CarbonImmutable $startsAtLocal,
        string $cronExpression,
        string $timezone,
        ?CarbonImmutable $now = null,
    ): ?CarbonImmutable {
        if (trim($cronExpression) === '') {
            return null;
        }

        $cron = new CronExpression($cronExpression);
        $referenceNow = $this->appNow($now)->setTimezone($timezone);

        if (
            $startsAtLocal !== null
            && $startsAtLocal->greaterThan($referenceNow)
            && $cron->isDue($startsAtLocal, $timezone)
        ) {
            return $this->toAppTimezone($startsAtLocal);
        }

        $searchFrom = $startsAtLocal !== null && $startsAtLocal->greaterThan($referenceNow)
            ? $startsAtLocal
            : $referenceNow;

        $nextRunAt = CarbonImmutable::instance(
            $cron->getNextRunDate($searchFrom, 0, false, $timezone),
        );

        while ($nextRunAt->lessThanOrEqualTo($referenceNow)) {
            $nextRunAt = CarbonImmutable::instance(
                $cron->getNextRunDate($nextRunAt, 0, false, $timezone),
            );
        }

        return $this->toAppTimezone($nextRunAt);
    }

    public function nextRunAt(Reminder $reminder, CarbonImmutable $now): ?CarbonImmutable
    {
        $timezone = $this->reminderTimezone($reminder);
        $currentDueTime = CarbonImmutable::instance($reminder->next_run_at ?? $now)->setTimezone($timezone);
        $referenceNow = $this->toAppTimezone($now)->setTimezone($timezone);

        $nextRunAt = match ($reminder->schedule_type) {
            Reminder::SCHEDULE_ONCE => null,
            Reminder::SCHEDULE_INTERVAL => $this->nextIntervalRun($reminder, $currentDueTime, $referenceNow),
            Reminder::SCHEDULE_CRON => $this->nextCronRun($reminder, $currentDueTime, $referenceNow),
            default => null,
        };

        if (
            $nextRunAt !== null
            && $reminder->ends_at !== null
            && $nextRunAt->greaterThan(CarbonImmutable::instance($reminder->ends_at))
        ) {
            return null;
        }

        return $nextRunAt;
    }

    public function appTimezone(): string
    {
        return (string) config('app.timezone');
    }

    public function reminderTimezone(Reminder $reminder): string
    {
        $timezone = trim((string) $reminder->timezone);

        return $timezone !== '' ? $timezone : $this->appTimezone();
    }

    private function nextIntervalRun(
        Reminder $reminder,
        CarbonImmutable $currentDueTime,
        CarbonImmutable $referenceNow,
    ): ?CarbonImmutable {
        if ($reminder->interval_minutes === null || $reminder->interval_minutes < 1) {
            return null;
        }

        $nextRunAt = $currentDueTime;

        do {
            $nextRunAt = $nextRunAt->addMinutes($reminder->interval_minutes);
        } while ($nextRunAt->lessThanOrEqualTo($referenceNow));

        return $this->toAppTimezone($nextRunAt);
    }

    private function nextCronRun(
        Reminder $reminder,
        CarbonImmutable $currentDueTime,
        CarbonImmutable $referenceNow,
    ): ?CarbonImmutable {
        if ($reminder->cron_expression === null || trim($reminder->cron_expression) === '') {
            return null;
        }

        $timezone = $this->reminderTimezone($reminder);
        $cronExpression = new CronExpression($reminder->cron_expression);
        $nextRunAt = CarbonImmutable::instance(
            $cronExpression->getNextRunDate($currentDueTime, 0, false, $timezone),
        );

        while ($nextRunAt->lessThanOrEqualTo($referenceNow)) {
            $nextRunAt = CarbonImmutable::instance(
                $cronExpression->getNextRunDate($nextRunAt, 0, false, $timezone),
            );
        }

        return $this->toAppTimezone($nextRunAt);
    }
}
