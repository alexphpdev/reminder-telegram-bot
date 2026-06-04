<script lang="ts">
	import { goto } from '$app/navigation';
	import { page } from '$app/state';
	import { createMutation } from '@tanstack/svelte-query';
	import ReminderForm from '$lib/admin/ReminderForm.svelte';
	import {
		createDefaultReminderFormState,
		type ReminderFormErrorMap
	} from '$lib/admin/reminder-form';
	import type { AdminReminderUpsertPayload } from '$lib/schemas/admin';
	import { ApiError, createAdminReminder, getErrorMessage, parseOptionalPositiveInt, queryClient } from '$lib';

	const seededUserId = $derived(parseOptionalPositiveInt(page.url.searchParams.get('user_id')));
	const initialValue = $derived.by(() =>
		createDefaultReminderFormState({
			userId: seededUserId
		})
	);
	const cancelHref = $derived(seededUserId !== undefined ? `/admin/users/${seededUserId}` : '/admin/reminders');

	const createReminderMutation = createMutation(() => ({
		mutationFn: (payload: AdminReminderUpsertPayload) => createAdminReminder(payload)
	}));

	let formError = $state<string | null>(null);
	let fieldErrors = $state<ReminderFormErrorMap>({});

	async function handleSubmit(payload: AdminReminderUpsertPayload): Promise<void> {
		formError = null;
		fieldErrors = {};

		try {
			const response = await createReminderMutation.mutateAsync(payload);

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
	<title>Reminder Admin | Create Reminder</title>
</svelte:head>

<ReminderForm
	cancelHref={cancelHref}
	description="Create a reminder without touching the database manually. The payload is validated in the browser and again by Laravel before it reaches the scheduler."
	fieldErrors={fieldErrors}
	formError={formError}
	formKey={`new:${seededUserId ?? 'none'}`}
	initialValue={initialValue}
	onSubmit={handleSubmit}
	pending={createReminderMutation.isPending}
	submitLabel="Create reminder"
	title="New reminder"
/>
