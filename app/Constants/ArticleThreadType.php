<?php

namespace App\Constants;

final class ArticleThreadType
{
    public const AUTHOR_EDITOR = 'author_editor';

    public const EDITORIAL_INTERNAL = 'editorial_internal';

    public const REVIEWER_EDITORIAL = 'reviewer_editorial';

    public const REVIEW_COORDINATION_INTERNAL = 'review_coordination_internal';

    public const PRODUCTION_INTERNAL = 'production_internal';

    public const AUTHOR_PROOF = 'author_proof';

    public const PUBLISHER_INTERNAL = 'publisher_internal';

    public const DIRECT_PUBLICATION_INTERNAL = 'direct_publication_internal';

    public const SYSTEM_ACTIVITY = 'system_activity';

    public const CUSTOM_RESTRICTED = 'custom_restricted';

    public const ALL = [
        self::AUTHOR_EDITOR, self::EDITORIAL_INTERNAL, self::REVIEWER_EDITORIAL,
        self::REVIEW_COORDINATION_INTERNAL, self::PRODUCTION_INTERNAL, self::AUTHOR_PROOF,
        self::PUBLISHER_INTERNAL, self::DIRECT_PUBLICATION_INTERNAL,
        self::SYSTEM_ACTIVITY, self::CUSTOM_RESTRICTED,
    ];

    public const PRIVACY = [
        self::AUTHOR_EDITOR => 'author_visible',
        self::EDITORIAL_INTERNAL => 'editorial_confidential',
        self::REVIEWER_EDITORIAL => 'reviewer_confidential',
        self::REVIEW_COORDINATION_INTERNAL => 'editorial_confidential',
        self::PRODUCTION_INTERNAL => 'production_confidential',
        self::AUTHOR_PROOF => 'author_visible',
        self::PUBLISHER_INTERNAL => 'publisher_confidential',
        self::DIRECT_PUBLICATION_INTERNAL => 'direct_publication_confidential',
        self::SYSTEM_ACTIVITY => 'system_role_safe',
        self::CUSTOM_RESTRICTED => 'editorial_confidential',
    ];

    public const AUDIENCE_LABELS = [
        'author_visible' => 'Visible to Authors and Editors',
        'editorial_confidential' => 'Editorial Confidential',
        'reviewer_confidential' => 'Reviewer Confidential',
        'production_confidential' => 'Production Confidential',
        'publisher_confidential' => 'Publisher Confidential',
        'direct_publication_confidential' => 'Direct Publication — Super Admin and Publisher Only',
        'system_role_safe' => 'Role-safe System Activity',
    ];
}
