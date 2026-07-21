<?php

namespace App\Services\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Carbon;

class NotificationPreferenceService
{
    public function effective(User $user, string $category): array
    {
        $default = config("notification_system.preferences.{$category}");
        $override = $user->notificationPreferences()->where('category', $category)->first();
        $locked = (bool) ($default['locked'] ?? false);

        return [
            'category' => $category,
            'in_app' => ['enabled' => $locked ? true : (bool) ($override?->in_app_enabled ?? $default['in_app'] ?? true), 'locked' => $locked],
            'email' => [
                'mode' => $locked ? 'immediate' : ($override?->email_mode ?? $default['email'] ?? 'immediate'),
                'allowed_modes' => $locked ? ['immediate'] : ['immediate', 'digest', 'off'],
            ],
            'digest_frequency' => $override?->digest_frequency ?? (($override?->email_mode ?? $default['email'] ?? null) === 'digest' ? 'daily' : null),
            'quiet_hours' => ['start' => $override?->quiet_hours_start, 'end' => $override?->quiet_hours_end],
            'timezone' => $override?->timezone ?? 'UTC',
        ];
    }

    public function effectiveForEvent(User $user, string $eventType): array
    {
        $template = app(NotificationTemplateRegistry::class)->get($eventType);
        $effective = $this->effective($user, $template['category']);
        $mandatory = collect($template['mandatoryChannels'] ?? []);

        if ($mandatory->contains('in_app')) {
            $effective['in_app'] = ['enabled' => true, 'locked' => true];
        }
        if ($mandatory->contains('email')) {
            $effective['email'] = ['mode' => 'immediate', 'allowed_modes' => ['immediate']];
            $effective['digest_frequency'] = null;
        }

        $effective['event_type'] = $eventType;
        $effective['mandatory_channels'] = $mandatory->values()->all();

        return $effective;
    }

    public function shouldSendLegacyEmail(User $user, string $eventType): bool
    {
        return $this->effectiveForEvent($user, $eventType)['email']['mode'] === 'immediate';
    }

    public function isQuietHours(array $preference, ?Carbon $now = null): bool
    {
        $start = $preference['quiet_hours']['start'] ?? null;
        $end = $preference['quiet_hours']['end'] ?? null;
        if (! $start || ! $end || $start === $end) {
            return false;
        }

        $local = ($now ?? now())->copy()->timezone($preference['timezone'] ?: 'UTC');
        $current = $local->format('H:i:s');
        $start = substr((string) $start, 0, 8);
        $end = substr((string) $end, 0, 8);

        return $start < $end
            ? $current >= $start && $current < $end
            : $current >= $start || $current < $end;
    }

    public function all(User $user): array
    {
        return collect(config('notification_system.preferences', []))->keys()
            ->map(fn (string $category) => $this->effective($user, $category))->all();
    }

    public function update(User $user, array $items, ?string $timezone, ?array $quietHours): array
    {
        foreach ($items as $item) {
            $category = $item['category'];
            $default = config("notification_system.preferences.{$category}");
            if (! $default) {
                continue;
            }
            $locked = (bool) ($default['locked'] ?? false);
            NotificationPreference::updateOrCreate(
                ['user_id' => $user->id, 'category' => $category],
                [
                    'in_app_enabled' => $locked ? true : (bool) ($item['in_app_enabled'] ?? true),
                    'email_mode' => $locked ? 'immediate' : ($item['email_mode'] ?? $default['email']),
                    'digest_frequency' => $locked ? null : ($item['digest_frequency'] ?? null),
                    'quiet_hours_start' => $quietHours['start'] ?? null,
                    'quiet_hours_end' => $quietHours['end'] ?? null,
                    'timezone' => $timezone ?? 'UTC',
                ]
            );
        }

        return $this->all($user);
    }
}
