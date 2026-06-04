<script lang="ts">
	import {
		cloneReminderFormState,
		firstReminderFormError,
		mergeReminderFormErrors,
		parseReminderFormState
	} from './reminder-form';
	import type { AdminReminderUpsertPayload } from '$lib/schemas/admin';
	import type { ReminderFormErrorMap, ReminderFormState } from './reminder-form';

	type ReminderFormProps = {
		title: string;
		description: string;
		submitLabel: string;
		cancelHref: string;
		formKey: string;
		initialValue: ReminderFormState;
		pending?: boolean;
		formError?: string | null;
		fieldErrors?: ReminderFormErrorMap;
		onSubmit: (payload: AdminReminderUpsertPayload) => Promise<void>;
	};

	let {
		title,
		description,
		submitLabel,
		cancelHref,
		formKey,
		initialValue,
		pending = false,
		formError = null,
		fieldErrors = {},
		onSubmit
	}: ReminderFormProps = $props();

	// svelte-ignore state_referenced_locally
	let form = $state(cloneReminderFormState(initialValue));
	let localErrors = $state<ReminderFormErrorMap>({});
	let localFormError = $state<string | null>(null);
	// svelte-ignore state_referenced_locally
	let lastFormKey = $state(formKey);

	$effect(() => {
		if (formKey === lastFormKey) {
			return;
		}

		form = cloneReminderFormState(initialValue);
		localErrors = {};
		localFormError = null;
		lastFormKey = formKey;
	});

	const mergedErrors = $derived(mergeReminderFormErrors(localErrors, fieldErrors));
	const visibleFormError = $derived(localFormError ?? formError);

	function fieldError(field: keyof ReminderFormErrorMap): string | null {
		return firstReminderFormError(mergedErrors, field);
	}

	async function handleSubmit(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		localErrors = {};
		localFormError = null;

		const parsed = parseReminderFormState(form);

		if (!parsed.success) {
			localErrors = parsed.errors;
			return;
		}

		try {
			await onSubmit(parsed.data);
		} catch (error) {
			localFormError =
				error instanceof Error ? error.message : 'Unexpected request failure while saving reminder.';
		}
	}
</script>

<section class="surface-card p-5 sm:p-6">
	<div class="space-y-3">
		<p class="eyebrow">Reminder Write API</p>
		<h2 class="text-2xl font-semibold tracking-tight text-stone-950">{title}</h2>
		<p class="text-sm leading-6 text-stone-700">{description}</p>
		<p class="rounded-[1.4rem] bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950">
			Changing a reminder here resets any active snooze and recalculates the next run from the current
			time. Leave `chat_id` empty only when a primary Telegram chat is already configured.
		</p>
	</div>
</section>

<section class="surface-card p-5 sm:p-6">
	<form class="space-y-6" onsubmit={handleSubmit}>
		{#if visibleFormError}
			<p class="rounded-[1.4rem] bg-rose-50 px-4 py-3 text-sm leading-6 text-rose-900">
				{visibleFormError}
			</p>
		{/if}

		<div class="form-grid">
			<label class="form-field md:col-span-2">
				<span class="field-label">Message</span>
				<textarea
					bind:value={form.message}
					class="field-input field-input-textarea"
					name="message"
					placeholder="What should be sent to Telegram?"
					rows="5"
				></textarea>
				<span class="field-help">
					This is the exact reminder body stored in `reminders.message`.
				</span>
				{#if fieldError('message')}
					<span class="field-error">{fieldError('message')}</span>
				{/if}
			</label>

			<label class="form-field">
				<span class="field-label">Status</span>
				<select bind:value={form.status} class="field-input" name="status">
					<option value="active">Active</option>
					<option value="paused">Paused</option>
				</select>
				{#if fieldError('status')}
					<span class="field-error">{fieldError('status')}</span>
				{/if}
			</label>

			<label class="form-field">
				<span class="field-label">Schedule type</span>
				<select bind:value={form.schedule_type} class="field-input" name="schedule_type">
					<option value="once">One-time</option>
					<option value="interval">Interval</option>
					<option value="cron">Cron</option>
				</select>
				{#if fieldError('schedule_type')}
					<span class="field-error">{fieldError('schedule_type')}</span>
				{/if}
			</label>

			<label class="form-field">
				<span class="field-label">Timezone</span>
				<input
					bind:value={form.timezone}
					class="field-input"
					name="timezone"
					placeholder="Europe/Berlin"
				/>
				<span class="field-help">Reminder local datetimes are interpreted in this timezone.</span>
				{#if fieldError('timezone')}
					<span class="field-error">{fieldError('timezone')}</span>
				{/if}
			</label>

			<label class="form-field">
				<span class="field-label">Mention override</span>
				<input
					bind:value={form.mention_override}
					class="field-input"
					name="mention_override"
					placeholder="Optional custom mention label"
				/>
				<span class="field-help">Leave empty to use the linked Telegram user naming logic.</span>
				{#if fieldError('mention_override')}
					<span class="field-error">{fieldError('mention_override')}</span>
				{/if}
			</label>

			<label class="form-field">
				<span class="field-label">Chat id</span>
				<input
					bind:value={form.chat_id}
					class="field-input"
					inputmode="numeric"
					name="chat_id"
					placeholder="Internal telegram_chats.id"
				/>
				<span class="field-help">Blank means "use primary chat" if one exists.</span>
				{#if fieldError('chat_id')}
					<span class="field-error">{fieldError('chat_id')}</span>
				{/if}
			</label>

			<label class="form-field">
				<span class="field-label">User id</span>
				<input
					bind:value={form.user_id}
					class="field-input"
					inputmode="numeric"
					name="user_id"
					placeholder="Internal telegram_users.id"
				/>
				<span class="field-help">Optional. Blank keeps the reminder unbound to a single user.</span>
				{#if fieldError('user_id')}
					<span class="field-error">{fieldError('user_id')}</span>
				{/if}
			</label>
		</div>

		<label class="checkbox-card">
			<input bind:checked={form.ask_status} type="checkbox" />
			<span>
				<strong class="block text-sm font-semibold text-stone-950">Await status confirmation</strong>
				<span class="mt-1 block text-sm leading-6 text-stone-700">
					When enabled, Telegram messages keep the `Done/Later` buttons and can repeat after the stale
					timeout.
				</span>
			</span>
		</label>

		{#if form.schedule_type === 'once'}
			<div class="form-grid">
				<label class="form-field">
					<span class="field-label">Scheduled for</span>
					<input
						bind:value={form.scheduled_for_local}
						class="field-input"
						name="scheduled_for_local"
						type="datetime-local"
					/>
					<span class="field-help">Must be a future local datetime in the selected timezone.</span>
					{#if fieldError('scheduled_for_local')}
						<span class="field-error">{fieldError('scheduled_for_local')}</span>
					{/if}
				</label>
			</div>
		{/if}

		{#if form.schedule_type === 'interval'}
			<div class="form-grid">
				<label class="form-field">
					<span class="field-label">Starts at</span>
					<input
						bind:value={form.starts_at_local}
						class="field-input"
						name="starts_at_local"
						type="datetime-local"
					/>
					{#if fieldError('starts_at_local')}
						<span class="field-error">{fieldError('starts_at_local')}</span>
					{/if}
				</label>

				<label class="form-field">
					<span class="field-label">Interval minutes</span>
					<input
						bind:value={form.interval_minutes}
						class="field-input"
						inputmode="numeric"
						name="interval_minutes"
						placeholder="30"
					/>
					{#if fieldError('interval_minutes')}
						<span class="field-error">{fieldError('interval_minutes')}</span>
					{/if}
				</label>

				<label class="form-field">
					<span class="field-label">Ends at</span>
					<input
						bind:value={form.ends_at_local}
						class="field-input"
						name="ends_at_local"
						type="datetime-local"
					/>
					<span class="field-help">Optional stop boundary for interval dispatches.</span>
					{#if fieldError('ends_at_local')}
						<span class="field-error">{fieldError('ends_at_local')}</span>
					{/if}
				</label>
			</div>
		{/if}

		{#if form.schedule_type === 'cron'}
			<div class="form-grid">
				<label class="form-field md:col-span-2">
					<span class="field-label">Cron expression</span>
					<input
						bind:value={form.cron_expression}
						class="field-input"
						name="cron_expression"
						placeholder="0 9 * * 1-5"
					/>
					<span class="field-help">
						Uses the reminder timezone, not the server timezone.
					</span>
					{#if fieldError('cron_expression')}
						<span class="field-error">{fieldError('cron_expression')}</span>
					{/if}
				</label>

				<label class="form-field">
					<span class="field-label">Starts at</span>
					<input
						bind:value={form.starts_at_local}
						class="field-input"
						name="starts_at_local"
						type="datetime-local"
					/>
					<span class="field-help">Optional. Leave blank to start from the next cron match.</span>
					{#if fieldError('starts_at_local')}
						<span class="field-error">{fieldError('starts_at_local')}</span>
					{/if}
				</label>

				<label class="form-field">
					<span class="field-label">Ends at</span>
					<input
						bind:value={form.ends_at_local}
						class="field-input"
						name="ends_at_local"
						type="datetime-local"
					/>
					<span class="field-help">Optional hard stop for future cron runs.</span>
					{#if fieldError('ends_at_local')}
						<span class="field-error">{fieldError('ends_at_local')}</span>
					{/if}
				</label>
			</div>
		{/if}

		<div class="flex flex-col gap-3 sm:flex-row">
			<button class="action-button" disabled={pending} type="submit">
				{pending ? 'Saving...' : submitLabel}
			</button>
			<a class="action-button action-button-secondary" href={cancelHref}>Cancel</a>
		</div>
	</form>
</section>
