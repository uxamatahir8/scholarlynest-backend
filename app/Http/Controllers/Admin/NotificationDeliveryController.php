<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendNotificationJob;
use App\Models\NotificationEvent;
use App\Models\NotificationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NotificationDeliveryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'queued', 'sending', 'sent', 'failed', 'suppressed'])],
            'purpose' => 'nullable|string|max:120',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);
        $page = NotificationLog::query()
            ->when($validated['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($validated['purpose'] ?? null, fn ($query, $value) => $query->where('purpose', $value))
            ->latest('id')->cursorPaginate($validated['limit'] ?? 50);

        return response()->json(['data' => collect($page->items())->map(fn ($log) => $this->present($log)), 'meta' => [
            'next_cursor' => $page->nextCursor()?->encode(),
            'has_more' => $page->hasMorePages(),
            'permanently_failed_outbox_count' => NotificationEvent::whereNotNull('permanently_failed_at')->count(),
            'outbox_failures' => NotificationEvent::query()->whereNotNull('permanently_failed_at')
                ->latest('permanently_failed_at')->limit(10)->get([
                    'id', 'event_type', 'attempt_count', 'failure_code', 'permanently_failed_at',
                ])->map(fn (NotificationEvent $event) => [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'attempt_count' => $event->attempt_count,
                    'failure_code' => $event->failure_code,
                    'permanently_failed_at' => $event->permanently_failed_at?->toISOString(),
                ])->values(),
            'features' => collect(config('notification_system.features', []))->map(fn ($value) => (bool) $value)->all(),
        ]]);
    }

    public function show(NotificationLog $notificationDelivery): JsonResponse
    {
        return response()->json(['data' => $this->present($notificationDelivery, true)]);
    }

    public function retry(NotificationLog $notificationDelivery): JsonResponse
    {
        abort_unless($notificationDelivery->status === 'failed', 409, 'Only failed deliveries may be retried.');
        abort_if(isset($notificationDelivery->payload['redacted']), 409, 'Redacted sensitive deliveries cannot be retried.');
        $notificationDelivery->update([
            'status' => 'queued', 'queued_at' => now(), 'sending_at' => null, 'failed_at' => null,
            'last_error_code' => null, 'last_error_summary' => null, 'error_message' => null,
        ]);
        SendNotificationJob::dispatch($notificationDelivery->id)->afterCommit()->onQueue('default');

        return response()->json(['data' => $this->present($notificationDelivery->fresh())]);
    }

    private function present(NotificationLog $log, bool $detail = false): array
    {
        [$local, $domain] = array_pad(explode('@', $log->recipient_email, 2), 2, '');
        $masked = Str::mask($local, '*', 1).'@'.$domain;

        return array_filter([
            'id' => $log->id,
            'event_uuid' => $log->event?->event_uuid,
            'recipient' => $masked,
            'status' => $log->status,
            'channel' => $log->channel,
            'purpose' => $log->purpose,
            'privacy_variant' => $log->privacy_variant,
            'retry_count' => $log->retry_count,
            'queued_at' => $log->queued_at?->toISOString(),
            'sending_at' => $log->sending_at?->toISOString(),
            'sent_at' => $log->sent_at?->toISOString(),
            'failed_at' => $log->failed_at?->toISOString(),
            'error_code' => $log->last_error_code,
            'error_summary' => $detail ? $log->last_error_summary : null,
        ], fn ($value) => $value !== null);
    }
}
