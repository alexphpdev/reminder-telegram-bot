import {
	getAdminDashboard,
	getAdminReminder,
	getAdminReminders,
	getAdminTelegramUser,
	getAdminTelegramUsers,
	type AdminReminderListParams,
	type AdminTelegramUserListParams
} from '$lib/api/admin';

export function adminDashboardQueryOptions() {
	return {
		queryKey: ['admin', 'dashboard'] as const,
		queryFn: () => getAdminDashboard()
	};
}

export function adminRemindersQueryOptions(params: AdminReminderListParams) {
	return {
		queryKey: ['admin', 'reminders', params] as const,
		queryFn: () => getAdminReminders(params)
	};
}

export function adminReminderQueryOptions(id: number) {
	return {
		queryKey: ['admin', 'reminders', id] as const,
		queryFn: () => getAdminReminder(id)
	};
}

export function adminTelegramUsersQueryOptions(params: AdminTelegramUserListParams) {
	return {
		queryKey: ['admin', 'users', params] as const,
		queryFn: () => getAdminTelegramUsers(params)
	};
}

export function adminTelegramUserQueryOptions(id: number) {
	return {
		queryKey: ['admin', 'users', id] as const,
		queryFn: () => getAdminTelegramUser(id)
	};
}
