<?php

namespace App\Constants;

final class DirectPublicationStatus
{
    public const DRAFT = 'direct_publication_draft';

    public const READY = 'direct_publication_ready';

    public const SCHEDULED = 'scheduled_for_publication';

    public const PUBLISHED = 'published';

    public const UNPUBLISHED = 'unpublished';

    public const ALL = [self::DRAFT, self::READY, self::SCHEDULED, self::PUBLISHED, self::UNPUBLISHED];

    public static function label(string $status): string
    {
        return match ($status) {
            self::DRAFT => 'Direct Publication Draft',
            self::READY => 'Ready for Direct Publication',
            self::SCHEDULED => 'Scheduled for Publication',
            self::PUBLISHED => 'Published',
            self::UNPUBLISHED => 'Unpublished',
            default => str($status)->headline()->toString(),
        };
    }
}
