<?php

namespace App\Http\Controllers;

use App\Services\Notifications\NotificationPreferenceService;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationPreferenceController extends Controller
{
    public function __construct(private NotificationPreferenceService $preferences) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->preferences->all($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => 'required|array',
            'preferences.*.category' => ['required', Rule::in(config('notification_system.categories'))],
            'preferences.*.in_app_enabled' => 'sometimes|boolean',
            'preferences.*.email_mode' => ['sometimes', Rule::in(['immediate', 'digest', 'off'])],
            'preferences.*.digest_frequency' => ['nullable', Rule::in(['daily', 'weekly'])],
            'timezone' => ['nullable', Rule::in(DateTimeZone::listIdentifiers())],
            'quiet_hours.start' => 'nullable|date_format:H:i',
            'quiet_hours.end' => 'nullable|date_format:H:i',
        ]);

        return response()->json(['data' => $this->preferences->update(
            $request->user(),
            $validated['preferences'],
            $validated['timezone'] ?? 'UTC',
            $validated['quiet_hours'] ?? null,
        )]);
    }
}
