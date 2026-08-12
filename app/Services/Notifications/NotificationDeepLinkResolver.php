<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\UserNotification;

class NotificationDeepLinkResolver
{
    public function resolve(UserNotification $notification, User $user): ?array
    {
        $params = $notification->deep_link_params ?? [];
        $articleId = (int) ($params['article_id'] ?? $notification->article_id);
        $ticketId = (int) ($params['ticket_id'] ?? 0);
        $articleSlug = (string) ($params['article_slug'] ?? '');
        $publicationSlug = (string) ($params['publication_slug'] ?? '');
        $publicationType = (string) ($params['publication_type'] ?? '');
        $threadId = (int) ($params['thread_id'] ?? 0);

        $href = match ($notification->deep_link_key) {
            'article.workflow' => $articleId ? "/admin/articles/{$articleId}/workflow" : null,
            'article.edit' => $articleId ? "/admin/articles/{$articleId}/edit" : null,
            'editor.desk' => '/admin/editor',
            'sub_editor.desk' => '/admin/sub-editor',
            'reviewer.desk' => '/admin/reviewer',
            'copy_editor.desk' => '/admin/copy-editor',
            'publisher.desk' => '/admin/publisher',
            'issue.manager' => '/admin/issues',
            'support.ticket' => $ticketId ? "/admin/support/{$ticketId}" : '/admin/support',
            'admin.support.ticket' => $ticketId ? "/admin/support-tickets/{$ticketId}" : '/admin/support-tickets',
            'account.settings' => '/admin/settings',
            'notifications.center' => '/admin/notifications',
            'direct.publication' => $articleId ? "/admin/direct-publications/{$articleId}" : '/admin/direct-publications',
            'article.thread' => $articleId ? ($notification->article?->isDirectPublication()
                ? "/admin/direct-publications/{$articleId}?step=6&thread={$threadId}"
                : "/admin/articles/{$articleId}/workflow?thread={$threadId}") : null,
            'article.public' => $articleSlug && $publicationSlug && in_array($publicationType, ['magazine', 'journal'], true)
                ? '/'.($publicationType === 'journal' ? 'journals' : 'magazines')."/{$publicationSlug}/articles/{$articleSlug}"
                : null,
            default => null,
        };

        return $href ? ['key' => $notification->deep_link_key, 'href' => $href] : null;
    }
}
