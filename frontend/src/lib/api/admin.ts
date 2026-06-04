import {
	adminDashboardResponseSchema,
	adminReminderDetailResponseSchema,
	adminReminderListResponseSchema,
	type AdminReminderUpsertPayload,
	adminTelegramUserDetailResponseSchema,
	adminTelegramUserListResponseSchema
} from '$lib/schemas/admin';
import { buildPath } from '$lib/admin/url';
import { apiJson, apiVoid } from './client';

export type AdminReminderListParams = {
	page?: number;
	per_page?: number;
	search?: string;
	status?: 'active' | 'paused' | 'completed';
	schedule_type?: 'once' | 'interval' | 'cron';
	user_id?: number;
};

export type AdminTelegramUserListParams = {
	page?: number;
	per_page?: number;
	search?: string;
};

export function getAdminDashboard() {
	return apiJson('/admin/dashboard', adminDashboardResponseSchema);
}

export function getAdminReminders(params: AdminReminderListParams = {}) {
	return apiJson(
		buildPath('/admin/reminders', params),
		adminReminderListResponseSchema
	);
}

export function getAdminReminder(id: number) {
	return apiJson(`/admin/reminders/${id}`, adminReminderDetailResponseSchema);
}

export function createAdminReminder(payload: AdminReminderUpsertPayload) {
	return apiJson('/admin/reminders', adminReminderDetailResponseSchema, {
		method: 'POST',
		headers: {
			'content-type': 'application/json'
		},
		body: JSON.stringify(payload)
	});
}

export function updateAdminReminder(id: number, payload: AdminReminderUpsertPayload) {
	return apiJson(`/admin/reminders/${id}`, adminReminderDetailResponseSchema, {
		method: 'PATCH',
		headers: {
			'content-type': 'application/json'
		},
		body: JSON.stringify(payload)
	});
}

export function deleteAdminReminder(id: number) {
	return apiVoid(`/admin/reminders/${id}`, {
		method: 'DELETE'
	});
}

export function getAdminTelegramUsers(params: AdminTelegramUserListParams = {}) {
	return apiJson(
		buildPath('/admin/users', params),
		adminTelegramUserListResponseSchema
	);
}

export function getAdminTelegramUser(id: number) {
	return apiJson(`/admin/users/${id}`, adminTelegramUserDetailResponseSchema);
}
