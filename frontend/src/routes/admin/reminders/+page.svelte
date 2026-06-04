<script lang="ts">
	import { page } from '$app/state';
	import { createQuery } from '@tanstack/svelte-query';
	import {
		adminRemindersQueryOptions,
		buildPath,
		formatDateTime,
		formatReminderStatus,
		formatScheduleType,
		getErrorMessage,
		normalizeSearchParam,
		parseOptionalPositiveInt,
		parsePageParam,
		reminderDueLabel,
		statusTone,
		trimMessage,
		type ReminderScheduleType,
		type ReminderStatus
	} from '$lib';

	const statusOptions: ReminderStatus[] = ['active', 'paused', 'completed'];
	const scheduleTypeOptions: ReminderScheduleType[] = ['once', 'interval', 'cron'];

	function parseStatus(value: string | null): ReminderStatus | undefined {
		return value === 'active' || value === 'paused' || value === 'completed' ? value : undefined;
	}

	function parseScheduleType(value: string | null): ReminderScheduleType | undefined {
		return value === 'once' || value === 'interval' || value === 'cron' ? value : undefined;
	}

	const filters = $derived.by(() => ({
		page: parsePageParam(page.url.searchParams.get('page')),
		search: normalizeSearchParam(page.url.searchParams.get('search')),
		status: parseStatus(page.url.searchParams.get('status')),
		schedule_type: parseScheduleType(page.url.searchParams.get('schedule_type')),
		user_id: parseOptionalPositiveInt(page.url.searchParams.get('user_id'))
	}));

	const remindersQuery = createQuery(() => adminRemindersQueryOptions(filters));

	function remindersHref(
		overrides: Partial<{
			page: number | undefined;
			search: string | undefined;
			status: ReminderStatus | undefined;
			schedule_type: ReminderScheduleType | undefined;
			user_id: number | undefined;
		}>
	): string {
		return buildPath('/admin/reminders', {
			page: overrides.page ?? filters.page,
			search: overrides.search ?? filters.search,
			status: overrides.status ?? filters.status,
			schedule_type: overrides.schedule_type ?? filters.schedule_type,
			user_id: overrides.user_id ?? filters.user_id
		});
	}
</script>

<svelte:head>
	<title>Reminder Admin | Reminders</title>
</svelte:head>

<section class="surface-card p-5 sm:p-6">
	<div class="flex flex-col gap-4">
			<div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
				<div class="space-y-2">
					<p class="eyebrow">Reminder Operations</p>
					<h2 class="text-2xl font-semibold tracking-tight text-stone-950">All reminders</h2>
					<p class="text-sm leading-6 text-stone-700">
						Filter by status, schedule type, or user, then jump into create and edit flows without
						touching SQLite manually.
					</p>
				</div>

				<div class="flex flex-col gap-2 sm:flex-row">
					<a class="nav-chip nav-chip-active justify-center" href="/admin/reminders/new">
						Create reminder
					</a>
					<form action="/admin/reminders" class="flex flex-col gap-2 sm:flex-row">
						<input type="hidden" name="status" value={filters.status ?? ''} />
						<input type="hidden" name="schedule_type" value={filters.schedule_type ?? ''} />
						<input type="hidden" name="user_id" value={filters.user_id ?? ''} />
						<input
							class="rounded-2xl border border-stone-200 bg-white px-4 py-3 text-sm text-stone-800 outline-none transition focus:border-stone-400"
							name="search"
							placeholder="Search reminder text"
							value={filters.search ?? ''}
						/>
						<button class="nav-chip justify-center" type="submit">Search</button>
					</form>
				</div>
			</div>

		<div class="flex flex-col gap-3">
			<div class="flex flex-wrap gap-2">
				<a class={filters.status === undefined ? 'filter-chip filter-chip-active' : 'filter-chip'} href={remindersHref({ status: undefined, page: 1 })}>
					All statuses
				</a>
				{#each statusOptions as status}
					<a
						class={filters.status === status ? 'filter-chip filter-chip-active' : 'filter-chip'}
						href={remindersHref({ status, page: 1 })}
					>
						{formatReminderStatus(status)}
					</a>
				{/each}
			</div>

			<div class="flex flex-wrap gap-2">
				<a class={filters.schedule_type === undefined ? 'filter-chip filter-chip-active' : 'filter-chip'} href={remindersHref({ schedule_type: undefined, page: 1 })}>
					All schedules
				</a>
				{#each scheduleTypeOptions as scheduleType}
					<a
						class={filters.schedule_type === scheduleType ? 'filter-chip filter-chip-active' : 'filter-chip'}
						href={remindersHref({ schedule_type: scheduleType, page: 1 })}
					>
						{formatScheduleType(scheduleType)}
					</a>
				{/each}

				{#if filters.user_id !== undefined}
					<a class="filter-chip" href={remindersHref({ user_id: undefined, page: 1 })}>Clear user filter</a>
				{/if}
			</div>
		</div>
	</div>
</section>

{#if remindersQuery.isPending}
	<section class="surface-card p-5 sm:p-6">
		<p class="text-sm text-stone-700">Loading reminders...</p>
	</section>
{:else if remindersQuery.isError}
	<section class="surface-card p-5 sm:p-6">
		<p class="text-sm text-rose-900">{getErrorMessage(remindersQuery.error)}</p>
	</section>
{:else if remindersQuery.data.data.length === 0}
	<section class="surface-card p-5 sm:p-6">
		<p class="text-sm text-stone-700">No reminders matched the current filters.</p>
	</section>
{:else}
	<section class="grid gap-4">
		{#each remindersQuery.data.data as reminder}
			<a class="surface-card block p-5 transition hover:-translate-y-0.5 hover:shadow-[0_30px_80px_rgba(146,113,75,0.22)]" href={`/admin/reminders/${reminder.id}`}>
				<div class="flex flex-col gap-4">
					<div class="flex flex-wrap items-center gap-2">
						<span class={`status-pill ${statusTone(reminder.status)}`}>
							{formatReminderStatus(reminder.status)}
						</span>
						<span class="status-pill status-pill-neutral">{formatScheduleType(reminder.schedule_type)}</span>
						{#if reminder.ask_status}
							<span class="status-pill status-pill-neutral">Awaits status</span>
						{/if}
					</div>

					<div class="space-y-2">
						<h3 class="text-lg font-semibold text-stone-950">{trimMessage(reminder.message, 180)}</h3>
						<p class="text-sm leading-6 text-stone-700">{reminderDueLabel(reminder)}</p>
					</div>

					<div class="grid gap-3 sm:grid-cols-3">
						<div class="metric-card">
							<span class="metric-label">User</span>
							<strong>{reminder.user?.display_name ?? 'No user bound'}</strong>
							{#if reminder.user}
								<span class="metric-note">@{reminder.user.username ?? reminder.user.telegram_user_id}</span>
							{/if}
						</div>
						<div class="metric-card">
							<span class="metric-label">Chat</span>
							<strong>{reminder.chat?.title ?? 'No chat bound'}</strong>
							<span class="metric-note">{reminder.chat?.type ?? 'n/a'}</span>
						</div>
						<div class="metric-card">
							<span class="metric-label">Deliveries</span>
							<strong>{reminder.deliveries_count ?? 0}</strong>
							<span class="metric-note">Updated {formatDateTime(reminder.updated_at, 'n/a')}</span>
						</div>
					</div>
				</div>
			</a>
		{/each}
	</section>

	<section class="surface-card flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
		<p class="text-sm text-stone-700">
			Page {remindersQuery.data.meta.current_page} of {remindersQuery.data.meta.last_page}
			<br />
			Total reminders: {remindersQuery.data.meta.total}
		</p>

		<div class="flex gap-2">
			<a
				class={remindersQuery.data.meta.current_page === 1 ? 'filter-chip pointer-events-none opacity-50' : 'filter-chip'}
				href={remindersHref({ page: remindersQuery.data.meta.current_page - 1 })}
			>
				Previous
			</a>
			<a
				class={remindersQuery.data.meta.current_page >= remindersQuery.data.meta.last_page ? 'filter-chip pointer-events-none opacity-50' : 'filter-chip'}
				href={remindersHref({ page: remindersQuery.data.meta.current_page + 1 })}
			>
				Next
			</a>
		</div>
	</section>
{/if}
