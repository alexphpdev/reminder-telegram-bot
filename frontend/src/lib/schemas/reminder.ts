import { z } from 'zod';

export const reminderStatusSchema = z.enum(['active', 'paused', 'completed']);
export const reminderScheduleTypeSchema = z.enum(['once', 'interval', 'cron']);

export const reminderSummarySchema = z.object({
	id: z.number().int().positive(),
	message: z.string().min(1),
	status: reminderStatusSchema,
	schedule_type: reminderScheduleTypeSchema,
	next_run_at: z.string().nullable(),
	user_id: z.number().int().positive().nullable(),
	chat_id: z.number().int().positive().nullable()
});

export type ReminderSummary = z.infer<typeof reminderSummarySchema>;
