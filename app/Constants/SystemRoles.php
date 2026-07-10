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
        'editor' => [
            'display_name' => 'Editor',
            'description' => 'Screens manuscripts and manages peer review for assigned magazines.',
        ],
        'magazine_editor' => [
            'display_name' => 'Magazine Editor',
            'description' => 'Magazine-scoped editor for assigned publication areas.',
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
        'proofreader' => [
            'display_name' => 'Proofreader',
            'description' => 'Handles final proof review before publication.',
        ],
    ];

    public static function names(): array
    {
        return array_keys(self::DEFINITIONS);
    }
}
