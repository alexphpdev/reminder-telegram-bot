<script lang="ts">
	import { page } from '$app/state';
	import { onMount } from 'svelte';
	import { bootstrapTelegramMiniApp, type TelegramBootstrapState } from '$lib';

	let { children } = $props();

	const navItems = [
		{ href: '/admin', label: 'Overview' },
		{ href: '/admin/reminders', label: 'Reminders' },
		{ href: '/admin/users', label: 'Users' }
	];

	let telegram = $state<TelegramBootstrapState>({
		available: false,
		source: 'server',
		version: null,
		platform: null,
		colorScheme: null,
		error: null
	});

	onMount(() => {
		let active = true;

		void bootstrapTelegramMiniApp().then((state) => {
			if (active) {
				telegram = state;
			}
		});

		return () => {
			active = false;
		};
	});

	function navClass(href: string): string {
		const active = href === '/admin'
			? page.url.pathname === href
			: page.url.pathname.startsWith(href);

		return active ? 'nav-chip nav-chip-active' : 'nav-chip';
	}
</script>

<div class="min-h-screen px-4 py-6 sm:px-6">
	<div class="admin-shell">
		<section class="surface-card overflow-hidden p-5 sm:p-7">
			<div class="flex flex-col gap-5">
				<div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
					<div class="max-w-2xl space-y-3">
						<p class="eyebrow">Reminder Admin</p>
						<h1 class="text-balance text-3xl font-semibold tracking-tight text-stone-950 sm:text-5xl">
							Mobile-first admin routed through Caddy, powered by Laravel API and SvelteKit
						</h1>
						<p class="text-sm leading-6 text-stone-700 sm:text-base">
							`/admin*` and `/api/admin*` stay behind the same temporary basic auth gate, while the
							rest of the site keeps the public bot and webhook behavior intact.
						</p>
					</div>

					<div class="status-chip max-w-xs">
						<span class="status-label">Runtime</span>
						<strong>
							{telegram.available ? 'Telegram Mini App context' : 'Browser preview mode'}
						</strong>
						<p class="mt-2 text-xs leading-5 text-stone-600">
							Source: {telegram.source}
							{#if telegram.platform}
								<br />
								Platform: {telegram.platform}
							{/if}
							{#if telegram.version}
								<br />
								Version: {telegram.version}
							{/if}
						</p>
					</div>
				</div>

				<nav class="flex flex-wrap gap-2">
					{#each navItems as item}
						<a class={navClass(item.href)} href={item.href}>
							{item.label}
						</a>
					{/each}
				</nav>

				{#if telegram.error}
					<p class="rounded-2xl bg-rose-50 px-4 py-3 text-sm text-rose-900">
						{telegram.error}
					</p>
				{/if}
			</div>
		</section>

		{@render children()}
	</div>
</div>
