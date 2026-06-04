<script lang="ts">
	import { page } from '$app/state';
	import { createQuery } from '@tanstack/svelte-query';
	import {
		adminTelegramUsersQueryOptions,
		buildPath,
		formatDateTime,
		getErrorMessage,
		normalizeSearchParam,
		parsePageParam
	} from '$lib';

	const filters = $derived.by(() => ({
		page: parsePageParam(page.url.searchParams.get('page')),
		search: normalizeSearchParam(page.url.searchParams.get('search'))
	}));

	const usersQuery = createQuery(() => adminTelegramUsersQueryOptions(filters));

	function usersHref(overrides: Partial<{ page: number | undefined; search: string | undefined }>): string {
		return buildPath('/admin/users', {
			page: overrides.page ?? filters.page,
			search: overrides.search ?? filters.search
		});
	}
</script>

<svelte:head>
	<title>Reminder Admin | Users</title>
</svelte:head>

<section class="surface-card p-5 sm:p-6">
	<div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
		<div class="space-y-2">
			<p class="eyebrow">Telegram Directory</p>
			<h2 class="text-2xl font-semibold tracking-tight text-stone-950">Known users</h2>
			<p class="text-sm leading-6 text-stone-700">
				Search by display fields or Telegram user id and inspect a user’s recent reminders from the
				admin UI.
			</p>
		</div>

		<form action="/admin/users" class="flex flex-col gap-2 sm:flex-row">
			<input
				class="rounded-2xl border border-stone-200 bg-white px-4 py-3 text-sm text-stone-800 outline-none transition focus:border-stone-400"
				name="search"
				placeholder="Search name, username, or telegram id"
				value={filters.search ?? ''}
			/>
			<button class="nav-chip nav-chip-active justify-center" type="submit">Search</button>
		</form>
	</div>
</section>

{#if usersQuery.isPending}
	<section class="surface-card p-5 sm:p-6">
		<p class="text-sm text-stone-700">Loading users...</p>
	</section>
{:else if usersQuery.isError}
	<section class="surface-card p-5 sm:p-6">
		<p class="text-sm text-rose-900">{getErrorMessage(usersQuery.error)}</p>
	</section>
{:else if usersQuery.data.data.length === 0}
	<section class="surface-card p-5 sm:p-6">
		<p class="text-sm text-stone-700">No users matched the current search.</p>
	</section>
{:else}
	<section class="grid gap-4">
		{#each usersQuery.data.data as user}
			<a class="surface-card block p-5 transition hover:-translate-y-0.5 hover:shadow-[0_30px_80px_rgba(146,113,75,0.22)]" href={`/admin/users/${user.id}`}>
				<div class="flex flex-col gap-4">
					<div class="flex flex-wrap items-center gap-2">
						<span class={`status-pill ${user.is_active ? 'status-pill-active' : 'status-pill-completed'}`}>
							{user.is_active ? 'Active' : 'Inactive'}
						</span>
						{#if user.is_bot}
							<span class="status-pill status-pill-neutral">Bot</span>
						{/if}
					</div>

					<div class="space-y-1">
						<h3 class="text-lg font-semibold text-stone-950">{user.display_name}</h3>
						<p class="text-sm text-stone-700">
							@{user.username ?? user.telegram_user_id}
							{#if user.timezone}
								<br />
								Timezone: {user.timezone}
							{/if}
						</p>
					</div>

					<div class="grid gap-3 sm:grid-cols-4">
						<div class="metric-card">
							<span class="metric-label">Total</span>
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
							<span class="metric-label">Seen</span>
							<strong>{formatDateTime(user.updated_at, 'n/a')}</strong>
						</div>
					</div>
				</div>
			</a>
		{/each}
	</section>

	<section class="surface-card flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
		<p class="text-sm text-stone-700">
			Page {usersQuery.data.meta.current_page} of {usersQuery.data.meta.last_page}
			<br />
			Total users: {usersQuery.data.meta.total}
		</p>

		<div class="flex gap-2">
			<a
				class={usersQuery.data.meta.current_page === 1 ? 'filter-chip pointer-events-none opacity-50' : 'filter-chip'}
				href={usersHref({ page: usersQuery.data.meta.current_page - 1 })}
			>
				Previous
			</a>
			<a
				class={usersQuery.data.meta.current_page >= usersQuery.data.meta.last_page ? 'filter-chip pointer-events-none opacity-50' : 'filter-chip'}
				href={usersHref({ page: usersQuery.data.meta.current_page + 1 })}
			>
				Next
			</a>
		</div>
	</section>
{/if}
