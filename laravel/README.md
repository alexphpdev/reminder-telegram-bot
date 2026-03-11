# Family Reminder Telegram Bot

Laravel 12 application for one family Telegram group. The bot sends scheduled reminders into the group, can mention a specific person, stores all schedules in SQLite, and receives Telegram updates over a webhook.

## What is implemented

- `POST /api/telegram/webhook` endpoint for Telegram updates
- local polling mode via Telegram `getUpdates`
- Telegram API client without third-party bot packages
- Scheduler command that checks due reminders every minute
- SQLite tables for chats, users, reminders, deliveries, and raw Telegram updates
- Docker `scheduler` service so reminders work without host cron

## Environment

Add these values to `.env`:

```dotenv
APP_URL=https://your-public-domain.example
APP_TIMEZONE=UTC

DB_CONNECTION=sqlite
DB_DATABASE=/var/lib/reminder-bot/sqlite/database.sqlite

TELEGRAM_BOT_TOKEN=123456:abc
TELEGRAM_WEBHOOK_SECRET=some-random-secret
TELEGRAM_WEBHOOK_URL=https://your-public-domain.example/api/telegram/webhook
TELEGRAM_PRIMARY_CHAT_ID=-1001234567890
TELEGRAM_MESSAGE_LOCALE=ru
TELEGRAM_STATUS_RESPONSE_TIMEOUT_MINUTES=15
```

`TELEGRAM_WEBHOOK_URL` is optional if `APP_URL` already points to the public HTTPS address.

## Docker

Start or rebuild containers:

```bash
docker compose up -d --build
```

SQLite data is stored in repo-root `./database` and is mounted inside containers at
`/var/lib/reminder-bot/sqlite/database.sqlite`.
This keeps the DB outside the Laravel source tree while still visible on the host.
Create host directory `./database` and prepare file permissions yourself before first start.

Example host preparation:

```bash
mkdir -p database
chmod 775 database
touch database/database.sqlite
chmod 664 database/database.sqlite
```

PHP containers run as `www-data`, so host ownership/permissions must allow that user to write the bind-mounted DB directory and file.
If you already have data in legacy `laravel/database/database.sqlite`, move or copy it manually into `./database/database.sqlite` before rebuilding containers.

Run migrations:

```bash
docker exec app-php php artisan migrate
```

Register the webhook:

```bash
docker exec app-php php artisan telegram:set-webhook
```

Telegram requires a public HTTPS URL. `http://localhost:8080` is not enough for real webhook delivery.

To inspect SQLite directly inside Docker:

```bash
docker exec -it app-php sqlite3 /var/lib/reminder-bot/sqlite/database.sqlite
```

## Webhook vs polling

Two delivery modes are supported:

- `webhook`: production mode. Telegram pushes updates to your public HTTPS endpoint.
- `polling`: local development mode. The app calls Telegram `getUpdates` and works without a public domain.

For local polling, first disable the webhook and then start the poller:

```bash
docker exec app-php php artisan telegram:delete-webhook
docker exec app-php php artisan telegram:poll-updates --once
docker compose --profile polling up -d poller
```

The `poller` service starts `telegram:poll-updates --take-over`, so it automatically disables the webhook before it begins polling.

## Reminder model

Create reminders directly in SQLite through the `reminders` table. Supported modes:

- `schedule_type = once` with `next_run_at`
- `schedule_type = interval` with `interval_minutes` and `next_run_at`
- `schedule_type = cron` with `cron_expression` and `next_run_at`

Useful fields:

- `chat_id`: local row from `telegram_chats`; if empty, the bot uses the primary chat
- `user_id`: local row from `telegram_users`; when present, the bot prepends a Telegram mention
  (mention label priority: `telegram_users.pseudonym` -> `telegram_users.first_name` -> fallback)
- `ask_status = 1`: adds inline buttons so the recipient can mark the reminder as done or snooze it for 30 minutes
- if `ask_status = 1` and there is no `Сделано/Позже` response within `TELEGRAM_STATUS_RESPONSE_TIMEOUT_MINUTES`, the bot re-sends the same reminder and removes the previous unanswered message
- `timezone`: per-reminder timezone for cron calculation

## One-time reminder flow in group chat

- Start via menu button `🟢➕ Добавить напоминание` or command `/once`.
- Steps:
  1) reminder text
  2) day selection (today/tomorrow + next 5 days)
  3) time input in `HH:MM`
  4) confirm / cancel
- Intermediate flow messages are sent silently and removed on finish/cancel.
- Final summary message is also silent.
- User timezone source:
  - `telegram_users.timezone` (IANA format, e.g. `Europe/Berlin`)
  - fallback to `UTC` when empty.
- Mention label source in confirmations/summaries:
  - `telegram_users.pseudonym` when not empty
  - otherwise `telegram_users.first_name`
  - rendered as HTML tg-link (`tg://user?id=...`).
- Command `/cancel` cancels an active draft.

## "My reminders" list in group chat

- Open via menu button `📋 Мои напоминания` or command `/myreminders`.
- Shows only future reminders for the current user.
- Bot sends the list silently (`disable_notification=true`).
- List time is rendered in each reminder timezone (`reminders.timezone`), not raw app/database timezone.
- IDs are not shown.
- If all reminders are active, no status labels are shown.
- If at least one reminder is paused, statuses are shown for all rows:
  - active: `🟢`
  - paused: `🟡`
- Inline actions:
  - each reminder row has a button to open its card (`myreminders:card:*`);
  - in card view: `Пауза/Возобновить`, `Удалить`, `Назад`, `Закрыть`;
  - delete uses confirmation step: `Подтвердить удаление` / `Отмена удаления`.
- Card view shows: text, next run, schedule type, timezone, status, and `snooze_until` (if set).
- `Закрыть` removes the list message and (when callback contains source ID) the original user message `📋 Мои напоминания`.

To delete user messages in group during cleanup, bot must have admin right `can_delete_messages`.

Example SQL:

```sql
insert into reminders (
    title,
    message,
    user_id,
    schedule_type,
    interval_minutes,
    timezone,
    next_run_at,
    ask_status,
    status,
    created_at,
    updated_at
) values (
    'Move alarm clock',
    'Пора переставить будильник на послезавтра.',
    1,
    'interval',
    2880,
    'Europe/Berlin',
    '2026-03-08 19:30:00',
    1,
    'active',
    datetime('now'),
    datetime('now')
);
```

## Useful commands

```bash
docker exec app-php php artisan reminders:dispatch-due
docker exec app-php php artisan telegram:poll-updates --once
docker exec app-php php artisan telegram:set-webhook
docker exec app-php php artisan telegram:delete-webhook
```
