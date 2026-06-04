<script lang="ts">
	import { goto } from '$app/navigation';
	import { page } from '$app/state';
	import { createMutation, createQuery } from '@tanstack/svelte-query';
	import ReminderForm from '$lib/admin/ReminderForm.svelte';
	import {
		reminderFormStateFromDetail,
		type ReminderFormErrorMap
	} from '$lib/admin/reminder-form';
	import type { AdminReminderUpsertPayload } from '$lib/schemas/admin';
	import {
		adminReminderQueryOptions,
		ApiError,
		getErrorMessage,
		parseOptionalPositiveInt,
		queryClient,
		updateAdminReminder
	} from '$lib';

	const reminderId = $derived(parseOptionalPositiveInt(page.params.id) ?? 0);

	const reminderQuery = createQuery(() => ({
		...adminReminderQueryOptions(reminderId),
		enabled: reminderId > 0
	}));

	const updateReminderMutation = createMutation(() => ({
		mutationFn: ({
			id,
			payload
		}: {
			id: number;
			payload: AdminReminderUpsertPayload;
		}) => updateAdminReminder(id, payload)
	}));

	let formError = $state<string | null>(null);
	let fieldErrors = $state<ReminderFormErrorMap>({});

	async function handleSubmit(payload: AdminReminderUpsertPayload): Promise<void> {
		formError = null;
		fieldErrors = {};

		try {
			const response = await updateReminderMutation.mutateAsync({
				id: reminderId,
				payload
			});

			await queryClient.invalidateQueries({ queryKey: ['admin'] });
			await goto(`/admin/reminders/${response.data.id}`);
		} catch (error) {
			formError = getErrorMessage(error);

			if (error instanceof ApiError) {
				fieldErrors = error.validationErrors;
			}

			throw error;
		}
	}
</script>

<svelte:head>
	<title>Reminder Admin | Edit Reminder</title>
</svelte:head>

{#if reminderId <= 0}
	<section class="surface-card p-5 sm:p-6">
		<p class="text-sm text-rose-900">Invalid reminder id.</p>
	</section>
{:else if reminderQuery.isPending}
	<section class="surface-card p-5 sm:p-6">
		<p class="text-sm text-stone-700">Loading reminder for editing...</p>
	</section>
{:else if reminderQuery.isError}
	<section class="surface-card p-5 sm:p-6">
		<p class="text-sm text-rose-900">{getErrorMessage(reminderQuery.error)}</p>
	</section>
{:else}
	{@const reminder = reminderQuery.data.data}

	<ReminderForm
		cancelHref={`/admin/reminders/${reminder.id}`}
		description="Edit reminder message, target binding, or schedule. Saving recalculates the next run using the selected reminder timezone."
		fieldErrors={fieldErrors}
		formError={formError}
		formKey={`edit:${reminder.id}:${reminder.updated_at ?? 'none'}`}
		initialValue={reminderFormStateFromDetail(reminder)}
		onSubmit={handleSubmit}
		pending={updateReminderMutation.isPending}
		submitLabel="Save changes"
		title={`Edit reminder #${reminder.id}`}
	/>
{/if}
