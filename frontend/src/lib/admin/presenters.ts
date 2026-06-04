import type {
	AdminReminderDelivery,
	AdminReminderSummary,
	ReminderScheduleType,
	ReminderStatus
} from '../schemas/admin';

export function formatDateTime(value: string | null, fallback = 'Not scheduled'): string {
	if (value === null) {
		return fallback;
	}

	const date = new Date(value);

	if (Number.isNaN(date.getTime())) {
		return value;
	}

	return new Intl.DateTimeFormat('en-GB', {
		dateStyle: 'medium',
		timeStyle: 'short'
	}).format(date);
}

export function formatReminderStatus(status: ReminderStatus): string {
	switch (status) {
		case 'active':
			return 'Active';
		case 'paused':
			return 'Paused';
		case 'completed':
			return 'Completed';
	}
}

export function formatScheduleType(scheduleType: ReminderScheduleType): string {
	switch (scheduleType) {
		case 'once':
			return 'One-time';
		case 'interval':
			return 'Interval';
		case 'cron':
			return 'Cron';
	}
}

export function statusTone(status: ReminderStatus): string {
	switch (status) {
		case 'active':
			return 'status-pill-active';
		case 'paused':
			return 'status-pill-paused';
		case 'completed':
			return 'status-pill-completed';
	}
}

export function deliveryTone(status: AdminReminderDelivery['delivery_status']): string {
	switch (status) {
		case 'sent':
		case 'done':
			return 'status-pill-active';
		case 'needs_attention':
		case 'expired':
			return 'status-pill-paused';
		case 'failed':
		case 'dispatching':
			return 'status-pill-completed';
	}
}

export function getErrorMessage(error: unknown): string {
	if (error instanceof Error) {
		return error.message;
	}

	return 'Unexpected request failure.';
}

export function trimMessage(message: string, limit = 120): string {
	return message.length <= limit ? message : `${message.slice(0, limit).trimEnd()}...`;
}

export function reminderDueLabel(reminder: Pick<AdminReminderSummary, 'next_run_at' | 'snooze_until'>): string {
	if (reminder.snooze_until !== null) {
		return `Snoozed until ${formatDateTime(reminder.snooze_until)}`;
	}

	if (reminder.next_run_at !== null) {
		return `Next run ${formatDateTime(reminder.next_run_at)}`;
	}

	return 'No next run scheduled';
}
