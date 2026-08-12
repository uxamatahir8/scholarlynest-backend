<?php

namespace App\Services\Notifications;

use InvalidArgumentException;

class NotificationTemplateRegistry
{
    public function has(string $eventType): bool
    {
        return is_array(config('notification_system.templates', [])[$eventType] ?? null);
    }

    public function get(string $eventType): array
    {
        $template = config('notification_system.templates', [])[$eventType] ?? null;
        if (! is_array($template)) {
            throw new InvalidArgumentException("Unsupported notification event type [{$eventType}].");
        }

        return $template;
    }

    public function getVersion(string $eventType, int $version): array
    {
        $template = $this->get($eventType);
        if ((int) ($template['version'] ?? 0) !== $version || (int) ($template['schemaVersion'] ?? 0) !== $version) {
            throw new InvalidArgumentException("Unsupported notification template version [{$eventType}:{$version}].");
        }

        return $template;
    }

    public function render(string $text, array $data): string
    {
        return preg_replace_callback('/\{([a-z0-9_]+)\}/i', function (array $match) use ($data) {
            $value = $data[$match[1]] ?? '—';

            return is_scalar($value) ? (string) $value : '—';
        }, $text) ?? $text;
    }
}
