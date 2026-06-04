<script lang="ts">
	import { page } from '$app/state';
	import { createQuery } from '@tanstack/svelte-query';
	import {
		adminTelegramUserQueryOptions,
		buildPath,
		formatDateTime,
		formatReminderStatus,
		formatScheduleType,
		getErrorMessage,
		parseOptionalPositiveInt,
		reminderDueLabel,
		statusTone,
		trimMessage
	} from '$lib';

	const userId = $derived(parseOptionalPositiveInt(page.params.id) ?? 0);

	const userQuery = createQuery(() => ({
		...adminTelegramUserQueryOptions(userId),
		enabled: userId > 0
	}));
</script>

<svelte:head>
	<title>Reminder Admin | User Detail</title>
</svelte:head>

{#if userId <= 0}
	<section class="surface-card p-5 sm:p-6">
		<p class="text-sm text-rose-900">Invalid user id.</p>
	</section>
{:else if userQuery.isPending}
	<section class="surface-card p-5 sm:p-6">
		<p class="text-sm text-stone-700">Loading user details...</p>
	</section>
{:else if userQuery.isError}
	<section class="surface-card p-5 sm:p-6">
		<p class="text-sm text-rose-900">{getErrorMessage(userQuery.error)}</p>
	</section>
{:else}
	{@const user = userQuery.data.data}

	<section class="surface-card p-5 sm:p-6">
		<div class="flex flex-col gap-4">
				<div class="flex flex-wrap items-center gap-2">
					<a class="filter-chip" href="/admin/users">Back to users</a>
					<a class="filter-chip" href={buildPath('/admin/reminders', { user_id: user.id })}>
						Open filtered reminders
					</a>
					<a class="filter-chip" href={buildPath('/admin/reminders/new', { user_id: user.id })}>
						Create reminder for user
					</a>
				</div>

			<div class="space-y-3">
				<div class="flex flex-wrap items-center gap-2">
					<span class={`status-pill ${user.is_active ? 'status-pill-active' : 'status-pill-completed'}`}>
						{user.is_active ? 'Active' : 'Inactive'}
					</span>
					{#if user.is_bot}
						<span class="status-pill status-pill-neutral">Bot</span>
					{/if}
				</div>
				<h2 class="text-2xl font-semibold tracking-tight text-stone-950">{user.display_name}</h2>
				<p class="text-sm leading-6 text-stone-700">
					Telegram id: {user.telegram_user_id}
					{#if user.username}
						<br />
						Username: @{user.username}
					{/if}
					{#if user.timezone}
						<br />
						Timezone: {user.timezone}
					{/if}
				</p>
			</div>

			<div class="grid gap-3 sm:grid-cols-4">
				<div class="metric-card">
					<span class="metric-label">Total reminders</span>
					<strong>{user.reminders_count ?? 0}</strong>
				</div>
				<div class="metric-card">
					<span class="metric-label">Active</span>
					<strong>{user.active_reminders_count ?? 0}</strong>
				</div>
				<div class="metric-card">
					<span class="metric-label">Paused</span>
					<strong>{user.paused_reminders_count ?? 0}</strong>
				</div>
				<div class="metric-card">
					<span class="metric-label">Completed</span>
					<strong>{user.completed_reminders_count ?? 0}</strong>
				</div>
			</div>
		</div>
	</section>

	<section class="grid gap-5 lg:grid-cols-[0.85fr_1.15fr]">
		<div class="surface-card p-5 sm:p-6">
			<h3 class="text-lg font-semibold text-stone-950">Profile snapshot</h3>
			<dl class="mt-4 space-y-3 text-sm text-stone-700">
				<div class="flex items-center justify-between gap-3">
					<dt>First name</dt>
					<dd class="font-medium text-stone-950">{user.first_name ?? 'n/a'}</dd>
				</div>
				<div class="flex items-center justify-between gap-3">
					<dt>Last name</dt>
					<dd class="font-medium text-stone-950">{user.last_name ?? 'n/a'}</dd>
				</div>
				<div class="flex items-center justify-between gap-3">
					<dt>Pseudonym</dt>
					<dd class="font-medium text-stone-950">{user.pseudonym ?? 'n/a'}</dd>
				</div>
				<div class="flex items-center justify-between gap-3">
					<dt>Language</dt>
					<dd class="font-medium text-stone-950">{user.language_code ?? 'n/a'}</dd>
				</div>
				<div class="flex items-center justify-between gap-3">
					<dt>Created</dt>
					<dd class="font-medium text-stone-950">{formatDateTime(user.created_at, 'n/a')}</dd>
				</div>
				<div class="flex items-center justify-between gap-3">
					<dt>Updated</dt>
					<dd class="font-medium text-stone-950">{formatDateTime(user.updated_at, 'n/a')}</dd>
				</div>
			</dl>
		</div>

		<div class="surface-card p-5 sm:p-6">
			<h3 class="text-lg font-semibold text-stone-950">Recent reminders</h3>
			{#if user.recent_reminders.length === 0}
				<p class="mt-4 text-sm text-stone-700">No reminders are linked to this user yet.</p>
			{:else}
				<div class="mt-4 space-y-3">
					{#each user.recent_reminders as reminder}
						<a class="block rounded-[1.6rem] border border-stone-200 bg-white/80 px-4 py-4 transition hover:-translate-y-0.5" href={`/admin/reminders/${reminder.id}`}>
							<div class="flex flex-wrap items-center gap-2">
								<span class={`status-pill ${statusTone(reminder.status)}`}>
									{formatReminderStatus(reminder.status)}
								</span>
								<span class="status-pill status-pill-neutral">{formatScheduleType(reminder.schedule_type)}</span>
							</div>
							<h4 class="mt-3 font-semibold text-stone-950">{trimMessage(reminder.message, 160)}</h4>
							<p class="mt-2 text-sm leading-6 text-stone-700">{reminderDueLabel(reminder)}</p>
						</a>
					{/each}
				</div>
			{/if}
		</div>
	</section>
{/if}
