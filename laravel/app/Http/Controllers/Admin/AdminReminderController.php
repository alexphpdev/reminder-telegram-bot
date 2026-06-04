<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminReminderUpsertRequest;
use App\Http\Resources\Admin\AdminReminderDetailResource;
use App\Http\Resources\Admin\AdminReminderSummaryResource;
use App\Models\Reminder;
use App\Services\Reminders\AdminReminderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class AdminReminderController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in([
                Reminder::STATUS_ACTIVE,
                Reminder::STATUS_PAUSED,
                Reminder::STATUS_COMPLETED,
            ])],
            'schedule_type' => ['nullable', Rule::in([
                Reminder::SCHEDULE_ONCE,
                Reminder::SCHEDULE_INTERVAL,
                Reminder::SCHEDULE_CRON,
            ])],
            'user_id' => ['nullable', 'integer', 'exists:telegram_users,id'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Reminder::query()
            ->with(['chat', 'user'])
            ->withCount('deliveries')
            ->when(
                $validated['status'] ?? null,
                fn ($builder, string $status) => $builder->where('status', $status)
            )
            ->when(
                $validated['schedule_type'] ?? null,
                fn ($builder, string $scheduleType) => $builder->where('schedule_type', $scheduleType)
            )
            ->when(
                $validated['user_id'] ?? null,
                fn ($builder, int $userId) => $builder->where('user_id', $userId)
            )
            ->when(
                $validated['search'] ?? null,
                fn ($builder, string $search) => $builder->where('message', 'like', '%'.$search.'%')
            )
            ->orderByRaw('CASE WHEN next_run_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('next_run_at')
            ->orderByDesc('updated_at');

        return AdminReminderSummaryResource::collection(
            $query->paginate((int) ($validated['per_page'] ?? 20))->withQueryString()
        );
    }

    public function show(Reminder $reminder): AdminReminderDetailResource
    {
        $reminder->load([
            'chat',
            'user',
            'deliveries' => fn ($builder) => $builder
                ->latest('sent_at')
                ->latest('id')
                ->limit(5),
        ]);
        $reminder->loadCount('deliveries');

        return new AdminReminderDetailResource($reminder);
    }

    public function store(
        AdminReminderUpsertRequest $request,
        AdminReminderManager $adminReminderManager,
    ): JsonResponse {
        return (new AdminReminderDetailResource(
            $adminReminderManager->create($request->validated()),
        ))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        AdminReminderUpsertRequest $request,
        Reminder $reminder,
        AdminReminderManager $adminReminderManager,
    ): AdminReminderDetailResource {
        return new AdminReminderDetailResource(
            $adminReminderManager->update($reminder, $request->validated()),
        );
    }

    public function destroy(Reminder $reminder, AdminReminderManager $adminReminderManager): Response
    {
        $adminReminderManager->delete($reminder);

        return response()->noContent();
    }
}
