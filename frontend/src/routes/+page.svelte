<script lang="ts">
	import { onMount } from 'svelte';
	import { API_BASE_PATH, bootstrapTelegramMiniApp, type TelegramBootstrapState } from '$lib';

	const stack = [
		'SvelteKit + TypeScript',
		'Adapter Node for Docker runtime',
		'Tailwind CSS mobile-first shell',
		'TanStack Query provider',
		'Zod schema layer',
		'TMA SDK bootstrap wrapper'
	];

	const routes = ['/admin', '/admin/reminders', '/admin/users', '/admin/users/[id]'];

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
</script>

<svelte:head>
	<title>Reminder Admin</title>
	<meta
		name="description"
		content="Mobile-first admin shell for reminder management and Telegram Mini App adaptation."
	/>
</svelte:head>

<div class="min-h-screen px-4 py-6 sm:px-6">
	<div class="mx-auto flex max-w-5xl flex-col gap-5">
		<section class="surface-card overflow-hidden p-5 sm:p-7">
			<div class="flex flex-col gap-4">
				<div class="flex items-center justify-between gap-3">
					<p class="eyebrow">Stage 1 Ready</p>
					<span class="inline-flex items-center rounded-full bg-white/80 px-3 py-1 text-xs font-semibold text-stone-700 ring-1 ring-stone-200">
						SSR capable
					</span>
				</div>

				<div class="max-w-2xl space-y-3">
					<h1 class="text-balance text-3xl font-semibold tracking-tight text-stone-950 sm:text-5xl">
						Reminder Admin shell for mobile and Telegram Mini App mode
					</h1>
					<p class="max-w-xl text-sm leading-6 text-stone-700 sm:text-base">
						Frontend is bootstrapped as a separate SvelteKit app. It keeps server rendering on by
						default, but is already safe to initialize Telegram-specific behavior only on the client.
					</p>
				</div>

				<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
					<div class="status-chip">
						<span class="status-label">Transport</span>
						<strong>Same-origin {API_BASE_PATH}</strong>
					</div>
					<div class="status-chip">
						<span class="status-label">Telegram</span>
						<strong>{telegram.available ? 'Mini App detected' : telegram.source}</strong>
					</div>
					<div class="status-chip">
						<span class="status-label">Runtime</span>
						<strong>Node adapter</strong>
					</div>
				</div>
			</div>
		</section>

		<section class="grid gap-5 lg:grid-cols-[1.2fr_0.8fr]">
			<div class="surface-card p-5 sm:p-6">
				<div class="flex items-center justify-between gap-3">
					<h2 class="text-lg font-semibold text-stone-950">Foundation stack</h2>
					<span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900">
						bootstrapped
					</span>
				</div>

				<div class="mt-4 grid gap-3 sm:grid-cols-2">
					{#each stack as item}
						<div class="rounded-2xl border border-stone-200 bg-white/80 px-4 py-3 text-sm text-stone-700 shadow-[0_10px_25px_rgba(120,98,67,0.08)]">
							{item}
						</div>
					{/each}
				</div>
			</div>

			<div class="surface-card p-5 sm:p-6">
				<h2 class="text-lg font-semibold text-stone-950">Telegram status</h2>
				<dl class="mt-4 space-y-3 text-sm text-stone-700">
					<div class="flex items-center justify-between gap-3">
						<dt>Source</dt>
						<dd class="font-medium text-stone-950">{telegram.source}</dd>
					</div>
					<div class="flex items-center justify-between gap-3">
						<dt>Platform</dt>
						<dd class="font-medium text-stone-950">{telegram.platform ?? 'n/a'}</dd>
					</div>
					<div class="flex items-center justify-between gap-3">
						<dt>Version</dt>
						<dd class="font-medium text-stone-950">{telegram.version ?? 'n/a'}</dd>
					</div>
					<div class="flex items-center justify-between gap-3">
						<dt>Color scheme</dt>
						<dd class="font-medium text-stone-950">{telegram.colorScheme ?? 'n/a'}</dd>
					</div>
				</dl>

				{#if telegram.error}
					<p class="mt-4 rounded-2xl bg-rose-50 px-4 py-3 text-sm text-rose-900">
						{telegram.error}
					</p>
				{:else}
					<p class="mt-4 rounded-2xl bg-stone-100 px-4 py-3 text-sm text-stone-700">
						Telegram-specific init runs only in the browser and does not affect SSR.
					</p>
				{/if}
			</div>
		</section>

		<section class="grid gap-5 lg:grid-cols-[0.95fr_1.05fr]">
			<div class="surface-card p-5 sm:p-6">
				<h2 class="text-lg font-semibold text-stone-950">Planned first routes</h2>
				<ul class="mt-4 space-y-3 text-sm text-stone-700">
					{#each routes as route}
						<li class="rounded-2xl border border-dashed border-stone-300 px-4 py-3 font-medium">
							{route}
						</li>
					{/each}
				</ul>
			</div>

			<div class="surface-card p-5 sm:p-6">
				<h2 class="text-lg font-semibold text-stone-950">What Stage 1 gives us</h2>
				<div class="mt-4 space-y-3 text-sm leading-6 text-stone-700">
					<p>
						Separate frontend workspace, adapter-node target, Tailwind-based shell, baseline data
						layer utilities, and a client-safe Telegram bootstrap point.
					</p>
					<p>
						Next stages can now focus on Docker wiring, Caddy routing, and the actual admin CRUD
						screens instead of project bootstrap.
					</p>
				</div>
			</div>
		</section>
	</div>
</div>
