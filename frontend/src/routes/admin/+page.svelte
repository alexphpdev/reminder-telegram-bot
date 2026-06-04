<script lang="ts">
	import { createQuery } from '@tanstack/svelte-query';
	import { adminDashboardQueryOptions, getErrorMessage } from '$lib';

	const dashboardQuery = createQuery(() => adminDashboardQueryOptions());
</script>

<svelte:head>
	<title>Reminder Admin | Overview</title>
	<meta name="description" content="Operational overview for reminders, users, and chats." />
</svelte:head>

{#if dashboardQuery.isPending}
	<section class="surface-card p-5 sm:p-6">
		<p class="text-sm text-stone-700">Loading dashboard...</p>
	</section>
{:else if dashboardQuery.isError}
	<section class="surface-card p-5 sm:p-6">
		<p class="text-sm text-rose-900">{getErrorMessage(dashboardQuery.error)}</p>
	</section>
{:else}
	{@const dashboard = dashboardQuery.data.data}

	<section class="grid gap-5 lg:grid-cols-3">
		<div class="surface-card p-5 sm:p-6">
			<p class="eyebrow">Reminders</p>
			<div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
				<div class="metric-card">
					<span class="metric-label">Total</span>
					<strong>{dashboard.reminders.total}</strong>
				</div>
				<div class="metric-card">
					<span class="metric-label">Due now</span>
					<strong>{dashboard.reminders.due_now}</strong>
				</div>
				<div class="metric-card">
					<span class="metric-label">Active / Paused / Completed</span>
					<strong>
						{dashboard.reminders.by_status.active} / {dashboard.reminders.by_status.paused} /
						{dashboard.reminders.by_status.completed}
					</strong>
				</div>
			</div>
		</div>

		<div class="surface-card p-5 sm:p-6">
			<p class="eyebrow">Users</p>
			<div class="mt-4 grid gap-3">
				<div class="metric-card">
					<span class="metric-label">Known users</span>
					<strong>{dashboard.users.total}</strong>
				</div>
				<div class="metric-card">
					<span class="metric-label">Active users</span>
					<strong>{dashboard.users.active}</strong>
				</div>
				<div class="metric-card">
					<span class="metric-label">Bots</span>
					<strong>{dashboard.users.bots}</strong>
				</div>
			</div>
		</div>

		<div class="surface-card p-5 sm:p-6">
			<p class="eyebrow">Chats</p>
			<div class="mt-4 grid gap-3">
				<div class="metric-card">
					<span class="metric-label">Known chats</span>
					<strong>{dashboard.chats.total}</strong>
				</div>
				<div class="metric-card">
					<span class="metric-label">Active chats</span>
					<strong>{dashboard.chats.active}</strong>
				</div>
				<div class="metric-card">
					<span class="metric-label">Primary chats</span>
					<strong>{dashboard.chats.primary}</strong>
				</div>
			</div>
		</div>
	</section>

	<section class="grid gap-5 lg:grid-cols-[0.95fr_1.05fr]">
		<div class="surface-card p-5 sm:p-6">
			<h2 class="text-lg font-semibold text-stone-950">Schedule mix</h2>
			<div class="mt-4 grid gap-3 sm:grid-cols-3">
				<div class="metric-card">
					<span class="metric-label">One-time</span>
					<strong>{dashboard.reminders.by_schedule.once}</strong>
				</div>
				<div class="metric-card">
					<span class="metric-label">Interval</span>
					<strong>{dashboard.reminders.by_schedule.interval}</strong>
				</div>
				<div class="metric-card">
					<span class="metric-label">Cron</span>
					<strong>{dashboard.reminders.by_schedule.cron}</strong>
				</div>
			</div>
		</div>

		<div class="surface-card p-5 sm:p-6">
			<h2 class="text-lg font-semibold text-stone-950">Next operator paths</h2>
				<div class="mt-4 grid gap-3">
					<a class="nav-chip nav-chip-active justify-center" href="/admin/reminders/new">
						Create reminder
					</a>
					<a class="nav-chip nav-chip-active justify-center" href="/admin/reminders">
						Inspect all reminders
					</a>
					<a class="nav-chip justify-center" href="/admin/users">
					Browse Telegram users
				</a>
				<a class="nav-chip justify-center" href="/admin/reminders?status=active">
					Focus only active reminders
				</a>
				</div>
				<p class="mt-4 text-sm leading-6 text-stone-700">
					The admin slice now covers read paths plus reminder create/edit/delete. The next step is
					polishing selection UX around users, chats, and schedule presets.
				</p>
			</div>
		</section>
	{/if}
