<script lang="ts">
	import { goto } from '$app/navigation';
	import { page } from '$app/state';
	import { createMutation, createQuery } from '@tanstack/svelte-query';
	import {
		adminReminderQueryOptions,
		deleteAdminReminder,
		deliveryTone,
		formatDateTime,
		formatReminderStatus,
		formatScheduleType,
		getErrorMessage,
		parseOptionalPositiveInt,
		queryClient,
		statusTone
	} from '$lib';

	const reminderId = $derived(parseOptionalPositiveInt(page.params.id) ?? 0);

	const reminderQuery = createQuery(() => ({
		...adminReminderQueryOptions(reminderId),
		enabled: reminderId > 0
	}));

	const deleteReminderMutation = createMutation(() => ({
		mutationFn: (id: number) => deleteAdminReminder(id)
	}));

	let deleteError = $state<string | null>(null);

	async function handleDelete(reminderMessage: string): Promise<void> {
		if (!window.confirm(`Delete reminder?\n\n${reminderMessage}`)) {
			return;
		}

		deleteError = null;

		try {
			await deleteReminderMutation.mutateAsync(reminderId);
			await queryClient.invalidateQueries({ queryKey: ['admin'] });
			await goto('/admin/reminders');
		} catch (error) {
			deleteError = getErrorMessage(error);
		}
	}
</script>

<svelte:head>
	<title>Reminder Admin | Reminder Detail</title>
</svelte:head>

{#if reminderId <= 0}
	<section class="surface-card p-5 sm:p-6">
		<p class="text-sm text-rose-900">Invalid reminder id.</p>
	</section>
{:else if reminderQuery.isPending}
	<section class="surface-card p-5 sm:p-6">
		<p class="text-sm text-stone-700">Loading reminder details...</p>
	</section>
{:else if reminderQuery.isError}
	<section class="surface-card p-5 sm:p-6">
		<p class="text-sm text-rose-900">{getErrorMessage(reminderQuery.error)}</p>
	</section>
{:else}
	{@const reminder = reminderQuery.data.data}

		<section class="surface-card p-5 sm:p-6">
			<div class="flex flex-col gap-4">
				<div class="flex flex-wrap items-center gap-2">
					<a class="filter-chip" href="/admin/reminders">Back to reminders</a>
					<a class="filter-chip" href={`/admin/reminders/${reminder.id}/edit`}>Edit reminder</a>
					{#if reminder.user}
						<a class="filter-chip" href={`/admin/users/${reminder.user.id}`}>Open user</a>
					{/if}
					<button
						class="filter-chip border-rose-300 text-rose-900"
						disabled={deleteReminderMutation.isPending}
						onclick={() => handleDelete(reminder.message)}
						type="button"
					>
						{deleteReminderMutation.isPending ? 'Deleting...' : 'Delete reminder'}
					</button>
				</div>

				{#if deleteError}
					<p class="rounded-[1.4rem] bg-rose-50 px-4 py-3 text-sm text-rose-900">{deleteError}</p>
				{/if}

				<div class="space-y-3">
					<div class="flex flex-wrap items-center gap-2">
					<span class={`status-pill ${statusTone(reminder.status)}`}>{formatReminderStatus(reminder.status)}</span>
					<span class="status-pill status-pill-neutral">{formatScheduleType(reminder.schedule_type)}</span>
				</div>
				<h2 class="text-2xl font-semibold tracking-tight text-stone-950">{reminder.message}</h2>
				<p class="text-sm leading-6 text-stone-700">
					Timezone: {reminder.timezone}
					{#if reminder.mention_override}
						<br />
						Mention override: {reminder.mention_override}
					{/if}
				</p>
			</div>

			<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
				<div class="metric-card">
					<span class="metric-label">Next run</span>
					<strong>{formatDateTime(reminder.next_run_at)}</strong>
				</div>
				<div class="metric-card">
					<span class="metric-label">Snooze until</span>
					<strong>{formatDateTime(reminder.snooze_until, 'Not snoozed')}</strong>
				</div>
				<div class="metric-card">
					<span class="metric-label">Last sent</span>
					<strong>{formatDateTime(reminder.last_sent_at, 'Never')}</strong>
				</div>
				<div class="metric-card">
					<span class="metric-label">Deliveries</span>
					<strong>{reminder.deliveries_count ?? 0}</strong>
				</div>
			</div>
		</div>
	</section>

	<section class="grid gap-5 lg:grid-cols-[0.9fr_1.1fr]">
		<div class="surface-card p-5 sm:p-6">
			<h3 class="text-lg font-semibold text-stone-950">Bound entities</h3>
			<div class="mt-4 space-y-4 text-sm text-stone-700">
				<div>
					<p class="metric-label">User</p>
					{#if reminder.user}
						<p class="font-medium text-stone-950">{reminder.user.display_name}</p>
						<p>@{reminder.user.username ?? reminder.user.telegram_user_id}</p>
					{:else}
						<p>No user linked.</p>
					{/if}
				</div>
				<div>
					<p class="metric-label">Chat</p>
					{#if reminder.chat}
						<p class="font-medium text-stone-950">{reminder.chat.title ?? reminder.chat.telegram_chat_id}</p>
						<p>{reminder.chat.type}</p>
					{:else}
						<p>No chat linked.</p>
					{/if}
				</div>
				<div>
					<p class="metric-label">Created</p>
					<p>{formatDateTime(reminder.created_at, 'n/a')}</p>
				</div>
				<div>
					<p class="metric-label">Updated</p>
					<p>{formatDateTime(reminder.updated_at, 'n/a')}</p>
				</div>
			</div>
		</div>

		<div class="surface-card p-5 sm:p-6">
			<h3 class="text-lg font-semibold text-stone-950">Recent deliveries</h3>
			{#if reminder.recent_deliveries.length === 0}
				<p class="mt-4 text-sm text-stone-700">No deliveries recorded yet.</p>
			{:else}
				<div class="mt-4 space-y-3">
					{#each reminder.recent_deliveries as delivery}
						<div class="rounded-[1.6rem] border border-stone-200 bg-white/80 px-4 py-4">
							<div class="flex flex-wrap items-center gap-2">
								<span class={`status-pill ${deliveryTone(delivery.delivery_status)}`}>
									{delivery.delivery_status}
								</span>
								<span class="text-xs font-medium text-stone-500">
									Sent {formatDateTime(delivery.sent_at, 'not sent')}
								</span>
							</div>
							<p class="mt-3 text-sm leading-6 text-stone-700">{delivery.message_text}</p>
							{#if delivery.error_message}
								<p class="mt-3 rounded-2xl bg-rose-50 px-4 py-3 text-sm text-rose-900">
									{delivery.error_message}
								</p>
							{/if}
						</div>
					{/each}
				</div>
			{/if}
		</div>
	</section>
{/if}
