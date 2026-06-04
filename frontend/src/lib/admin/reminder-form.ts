import type { ZodError } from 'zod';
import type {
	AdminReminderDetail,
	AdminReminderUpsertPayload,
	ReminderScheduleType,
	ReminderStatus
} from '$lib/schemas/admin';
import { adminReminderUpsertPayloadSchema } from '$lib/schemas/admin';

export type EditableReminderStatus = Extract<ReminderStatus, 'active' | 'paused'>;

export type ReminderFormState = {
	message: string;
	chat_id: string;
	user_id: string;
	mention_override: string;
	status: EditableReminderStatus;
	schedule_type: ReminderScheduleType;
	timezone: string;
	ask_status: boolean;
	scheduled_for_local: string;
	starts_at_local: string;
	ends_at_local: string;
	interval_minutes: string;
	cron_expression: string;
};

export type ReminderFormField = keyof ReminderFormState;
export type ReminderFormErrorMap = Partial<Record<ReminderFormField | 'form', string[]>>;

export function createDefaultReminderFormState(seed?: {
	userId?: number;
	timezone?: string;
}): ReminderFormState {
	return {
		message: '',
		chat_id: '',
		user_id: seed?.userId !== undefined ? String(seed.userId) : '',
		mention_override: '',
		status: 'active',
		schedule_type: 'once',
		timezone: seed?.timezone ?? resolveLocalTimezone(),
		ask_status: true,
		scheduled_for_local: '',
		starts_at_local: '',
		ends_at_local: '',
		interval_minutes: '',
		cron_expression: ''
	};
}

export function cloneReminderFormState(state: ReminderFormState): ReminderFormState {
	return { ...state };
}

export function reminderFormStateFromDetail(reminder: AdminReminderDetail): ReminderFormState {
	return {
		...createDefaultReminderFormState({
			userId: reminder.user_id ?? undefined,
			timezone: reminder.timezone
		}),
		message: reminder.message,
		chat_id: reminder.chat_id !== null ? String(reminder.chat_id) : '',
		user_id: reminder.user_id !== null ? String(reminder.user_id) : '',
		mention_override: reminder.mention_override ?? '',
		status: reminder.status === 'paused' ? 'paused' : 'active',
		schedule_type: reminder.schedule_type,
		timezone: reminder.timezone,
		ask_status: reminder.ask_status,
		scheduled_for_local:
			reminder.schedule_type === 'once'
				? formatIsoAsDateTimeLocal(reminder.next_run_at, reminder.timezone)
				: '',
		starts_at_local: formatIsoAsDateTimeLocal(reminder.starts_at, reminder.timezone),
		ends_at_local: formatIsoAsDateTimeLocal(reminder.ends_at, reminder.timezone),
		interval_minutes:
			reminder.interval_minutes !== null ? String(reminder.interval_minutes) : '',
		cron_expression: reminder.cron_expression ?? ''
	};
}

export function parseReminderFormState(
	state: ReminderFormState
): { success: true; data: AdminReminderUpsertPayload } | { success: false; errors: ReminderFormErrorMap } {
	const scheduleType = state.schedule_type;
	const payload = {
		message: state.message.trim(),
		chat_id: parseOptionalPositiveInteger(state.chat_id),
		user_id: parseOptionalPositiveInteger(state.user_id),
		mention_override: trimToNull(state.mention_override),
		status: state.status,
		schedule_type: scheduleType,
		timezone: state.timezone.trim(),
		ask_status: state.ask_status,
		scheduled_for_local: scheduleType === 'once' ? trimToNull(state.scheduled_for_local) : null,
		starts_at_local:
			scheduleType === 'interval' || scheduleType === 'cron'
				? trimToNull(state.starts_at_local)
				: null,
		ends_at_local:
			scheduleType === 'interval' || scheduleType === 'cron'
				? trimToNull(state.ends_at_local)
				: null,
		interval_minutes:
			scheduleType === 'interval' ? parseOptionalPositiveInteger(state.interval_minutes) : null,
		cron_expression: scheduleType === 'cron' ? trimToNull(state.cron_expression) : null
	};

	const result = adminReminderUpsertPayloadSchema.safeParse(payload);

	if (result.success) {
		return {
			success: true,
			data: result.data
		};
	}

	return {
		success: false,
		errors: zodErrorToFieldErrors(result.error)
	};
}

export function mergeReminderFormErrors(...maps: ReminderFormErrorMap[]): ReminderFormErrorMap {
	return maps.reduce<ReminderFormErrorMap>((carry, map) => {
		for (const [field, messages] of Object.entries(map)) {
			if (!Array.isArray(messages) || messages.length === 0) {
				continue;
			}

			const existing = carry[field as keyof ReminderFormErrorMap] ?? [];
			carry[field as keyof ReminderFormErrorMap] = [...existing, ...messages];
		}

		return carry;
	}, {});
}

export function firstReminderFormError(
	errors: ReminderFormErrorMap,
	field: keyof ReminderFormErrorMap
): string | null {
	const value = errors[field];

	return Array.isArray(value) && value.length > 0 ? value[0] : null;
}

function zodErrorToFieldErrors(error: ZodError): ReminderFormErrorMap {
	return error.issues.reduce<ReminderFormErrorMap>((carry, issue) => {
		const field = typeof issue.path[0] === 'string' ? issue.path[0] : 'form';

		carry[field as keyof ReminderFormErrorMap] = [
			...(carry[field as keyof ReminderFormErrorMap] ?? []),
			issue.message
		];

		return carry;
	}, {});
}

function parseOptionalPositiveInteger(value: string): number | null {
	const normalized = value.trim();

	if (normalized === '') {
		return null;
	}

	if (!/^\d+$/.test(normalized)) {
		return Number.NaN;
	}

	const parsed = Number.parseInt(normalized, 10);

	return parsed > 0 ? parsed : Number.NaN;
}

function trimToNull(value: string): string | null {
	const normalized = value.trim();

	return normalized === '' ? null : normalized;
}

function resolveLocalTimezone(): string {
	try {
		return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
	} catch {
		return 'UTC';
	}
}

function formatIsoAsDateTimeLocal(value: string | null, timezone: string): string {
	if (value === null) {
		return '';
	}

	const date = new Date(value);

	if (Number.isNaN(date.getTime())) {
		return '';
	}

	try {
		const formatter = new Intl.DateTimeFormat('sv-SE', {
			timeZone: timezone,
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
			hour: '2-digit',
			minute: '2-digit',
			hourCycle: 'h23'
		});
		const parts = Object.fromEntries(
			formatter
				.formatToParts(date)
				.filter((part) => part.type !== 'literal')
				.map((part) => [part.type, part.value])
		);

		return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
	} catch {
		return '';
	}
}
