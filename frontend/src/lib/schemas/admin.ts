import { z } from 'zod';
import { reminderScheduleTypeSchema, reminderStatusSchema } from './reminder';

const nullableTimestampSchema = z.string().min(1).nullable();
const nullableStringSchema = z.string().nullable();
const localDateTimeSchema = z.string().regex(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/);

export const adminTelegramChatSchema = z.object({
	id: z.number().int().positive(),
	telegram_chat_id: z.number().int(),
	title: nullableStringSchema,
	type: z.string().min(1),
	username: nullableStringSchema,
	is_primary: z.boolean(),
	is_active: z.boolean()
});

export const adminTelegramUserSummarySchema = z.object({
	id: z.number().int().positive(),
	telegram_user_id: z.number().int().positive(),
	username: nullableStringSchema,
	first_name: nullableStringSchema,
	last_name: nullableStringSchema,
	pseudonym: nullableStringSchema,
	display_name: z.string().min(1),
	language_code: nullableStringSchema,
	timezone: nullableStringSchema,
	is_bot: z.boolean(),
	is_active: z.boolean(),
	reminders_count: z.number().int().nullable(),
	active_reminders_count: z.number().int().nullable(),
	paused_reminders_count: z.number().int().nullable(),
	completed_reminders_count: z.number().int().nullable(),
	created_at: nullableTimestampSchema,
	updated_at: nullableTimestampSchema
});

export const adminReminderDeliverySchema = z.object({
	id: z.number().int().positive(),
	delivery_status: z.enum(['dispatching', 'sent', 'done', 'needs_attention', 'expired', 'failed']),
	telegram_message_id: z.number().int().nullable(),
	message_text: z.string().min(1),
	error_message: nullableStringSchema,
	sent_at: nullableTimestampSchema,
	acknowledged_at: nullableTimestampSchema,
	created_at: nullableTimestampSchema,
	updated_at: nullableTimestampSchema
});

export const adminReminderSummarySchema = z.object({
	id: z.number().int().positive(),
	message: z.string().min(1),
	status: reminderStatusSchema,
	schedule_type: reminderScheduleTypeSchema,
	cron_expression: nullableStringSchema,
	interval_minutes: z.number().int().positive().nullable(),
	timezone: z.string().min(1),
	mention_override: nullableStringSchema,
	ask_status: z.boolean(),
	user_id: z.number().int().positive().nullable(),
	chat_id: z.number().int().positive().nullable(),
	starts_at: nullableTimestampSchema,
	next_run_at: nullableTimestampSchema,
	snooze_until: nullableTimestampSchema,
	ends_at: nullableTimestampSchema,
	last_sent_at: nullableTimestampSchema,
	deliveries_count: z.number().int().nullable(),
	user: adminTelegramUserSummarySchema.nullable(),
	chat: adminTelegramChatSchema.nullable(),
	created_at: nullableTimestampSchema,
	updated_at: nullableTimestampSchema
});

export const adminReminderDetailSchema = adminReminderSummarySchema.extend({
	recent_deliveries: z.array(adminReminderDeliverySchema)
});

export const adminTelegramUserDetailSchema = adminTelegramUserSummarySchema.extend({
	recent_reminders: z.array(adminReminderSummarySchema)
});

const paginationLinkSchema = z.object({
	url: nullableStringSchema,
	label: z.string(),
	active: z.boolean()
});

const paginationEnvelopeSchema = z.object({
	current_page: z.number().int().positive(),
	from: z.number().int().nullable(),
	last_page: z.number().int().positive(),
	links: z.array(paginationLinkSchema),
	path: z.string().min(1),
	per_page: z.number().int().positive(),
	to: z.number().int().nullable(),
	total: z.number().int().nonnegative()
});

const paginationUrlsSchema = z.object({
	first: nullableStringSchema,
	last: nullableStringSchema,
	prev: nullableStringSchema,
	next: nullableStringSchema
});

function paginatedResponseSchema<T extends z.ZodTypeAny>(itemSchema: T) {
	return z.object({
		data: z.array(itemSchema),
		links: paginationUrlsSchema,
		meta: paginationEnvelopeSchema
	});
}

export const adminDashboardResponseSchema = z.object({
	data: z.object({
		reminders: z.object({
			total: z.number().int().nonnegative(),
			due_now: z.number().int().nonnegative(),
			by_status: z.object({
				active: z.number().int().nonnegative(),
				paused: z.number().int().nonnegative(),
				completed: z.number().int().nonnegative()
			}),
			by_schedule: z.object({
				once: z.number().int().nonnegative(),
				interval: z.number().int().nonnegative(),
				cron: z.number().int().nonnegative()
			})
		}),
		users: z.object({
			total: z.number().int().nonnegative(),
			active: z.number().int().nonnegative(),
			bots: z.number().int().nonnegative()
		}),
		chats: z.object({
			total: z.number().int().nonnegative(),
			active: z.number().int().nonnegative(),
			primary: z.number().int().nonnegative()
		})
	})
});

export const adminReminderListResponseSchema = paginatedResponseSchema(adminReminderSummarySchema);
export const adminReminderDetailResponseSchema = z.object({
	data: adminReminderDetailSchema
});
export const adminTelegramUserListResponseSchema = paginatedResponseSchema(
	adminTelegramUserSummarySchema
);
export const adminTelegramUserDetailResponseSchema = z.object({
	data: adminTelegramUserDetailSchema
});

export const adminReminderUpsertPayloadSchema = z
	.object({
		message: z.string().trim().min(1, 'Message is required.'),
		chat_id: z.number().int().positive().nullable(),
		user_id: z.number().int().positive().nullable(),
		mention_override: z.string().trim().max(255).nullable(),
		status: z.enum(['active', 'paused']),
		schedule_type: reminderScheduleTypeSchema,
		timezone: z.string().trim().min(1, 'Timezone is required.'),
		ask_status: z.boolean(),
		scheduled_for_local: localDateTimeSchema.nullable(),
		starts_at_local: localDateTimeSchema.nullable(),
		ends_at_local: localDateTimeSchema.nullable(),
		interval_minutes: z.number().int().positive().nullable(),
		cron_expression: z.string().trim().max(255).nullable()
	})
	.superRefine((payload, ctx) => {
		if (payload.schedule_type === 'once') {
			if (payload.scheduled_for_local === null) {
				ctx.addIssue({
					code: z.ZodIssueCode.custom,
					path: ['scheduled_for_local'],
					message: 'Pick a local date and time for one-time reminders.'
				});
			}

			if (payload.starts_at_local !== null) {
				ctx.addIssue({
					code: z.ZodIssueCode.custom,
					path: ['starts_at_local'],
					message: 'One-time reminders do not use a start date.'
				});
			}

			if (payload.ends_at_local !== null) {
				ctx.addIssue({
					code: z.ZodIssueCode.custom,
					path: ['ends_at_local'],
					message: 'One-time reminders do not use an end date.'
				});
			}

			if (payload.interval_minutes !== null) {
				ctx.addIssue({
					code: z.ZodIssueCode.custom,
					path: ['interval_minutes'],
					message: 'Interval minutes apply only to interval reminders.'
				});
			}

			if (payload.cron_expression !== null) {
				ctx.addIssue({
					code: z.ZodIssueCode.custom,
					path: ['cron_expression'],
					message: 'Cron expression applies only to cron reminders.'
				});
			}
		}

		if (payload.schedule_type === 'interval') {
			if (payload.starts_at_local === null) {
				ctx.addIssue({
					code: z.ZodIssueCode.custom,
					path: ['starts_at_local'],
					message: 'Interval reminders require a local start date.'
				});
			}

			if (payload.interval_minutes === null) {
				ctx.addIssue({
					code: z.ZodIssueCode.custom,
					path: ['interval_minutes'],
					message: 'Interval reminders require interval minutes.'
				});
			}

			if (payload.scheduled_for_local !== null) {
				ctx.addIssue({
					code: z.ZodIssueCode.custom,
					path: ['scheduled_for_local'],
					message: 'Scheduled time applies only to one-time reminders.'
				});
			}

			if (payload.cron_expression !== null) {
				ctx.addIssue({
					code: z.ZodIssueCode.custom,
					path: ['cron_expression'],
					message: 'Cron expression applies only to cron reminders.'
				});
			}
		}

		if (payload.schedule_type === 'cron') {
			if (payload.cron_expression === null) {
				ctx.addIssue({
					code: z.ZodIssueCode.custom,
					path: ['cron_expression'],
					message: 'Cron reminders require a cron expression.'
				});
			}

			if (payload.interval_minutes !== null) {
				ctx.addIssue({
					code: z.ZodIssueCode.custom,
					path: ['interval_minutes'],
					message: 'Interval minutes apply only to interval reminders.'
				});
			}

			if (payload.scheduled_for_local !== null) {
				ctx.addIssue({
					code: z.ZodIssueCode.custom,
					path: ['scheduled_for_local'],
					message: 'Scheduled time applies only to one-time reminders.'
				});
			}
		}
	});

export type ReminderStatus = z.infer<typeof reminderStatusSchema>;
export type ReminderScheduleType = z.infer<typeof reminderScheduleTypeSchema>;
export type AdminDashboardResponse = z.infer<typeof adminDashboardResponseSchema>;
export type AdminReminderSummary = z.infer<typeof adminReminderSummarySchema>;
export type AdminReminderDetail = z.infer<typeof adminReminderDetailSchema>;
export type AdminReminderUpsertPayload = z.infer<typeof adminReminderUpsertPayloadSchema>;
export type AdminReminderDelivery = z.infer<typeof adminReminderDeliverySchema>;
export type AdminTelegramUserSummary = z.infer<typeof adminTelegramUserSummarySchema>;
export type AdminTelegramUserDetail = z.infer<typeof adminTelegramUserDetailSchema>;
