<?php

namespace App\Constants;

final class SystemRoles
{
    public const DEFINITIONS = [
        'super_admin' => [
            'display_name' => 'Super Admin',
            'description' => 'Global platform administrator with unrestricted operational access.',
        ],
        'admin' => [
            'display_name' => 'Admin',
            'description' => 'Administrative operator for non-global platform management workflows.',
        ],
        'author' => [
            'display_name' => 'Author',
            'description' => 'Creates, submits, revises, and tracks owned manuscripts.',
        ],
        'super_editor' => [
            'display_name' => 'Super Editor',
            'description' => 'Manages editorial workflows for assigned magazines and journals.',
        ],
        'magazine_editor' => [
            'display_name' => 'Magazine Editor',
            'description' => 'Manages editorial workflows for assigned magazines only.',
        ],
        'journal_editor' => [
            'display_name' => 'Journal Editor',
            'description' => 'Manages editorial workflows for assigned journals only.',
        ],
        // Compatibility alias for tests/integrations that still build the former role directly.
        // SystemRoleSeeder intentionally does not create this role.
        'editor' => [
            'display_name' => 'Super Editor',
            'description' => 'Legacy alias for the Super Editor role.',
        ],
        'sub_editor' => [
            'display_name' => 'Sub Editor',
            'description' => 'Coordinates reviewer work and recommendations for assigned manuscripts.',
        ],
        'reviewer' => [
            'display_name' => 'Reviewer',
            'description' => 'Completes assigned peer review scorecards and recommendations.',
        ],
        'publisher' => [
            'display_name' => 'Publisher',
            'description' => 'Manages issues, publication metadata, and publishing actions.',
        ],
        'copy_editor' => [
            'display_name' => 'Copy Editor',
            'description' => 'Handles accepted manuscript copy editing production work.',
        ],
    ];

    public static function names(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function seededNames(): array
    {
        return array_values(array_filter(self::names(), fn ($name) => $name !== 'editor'));
    }
}
