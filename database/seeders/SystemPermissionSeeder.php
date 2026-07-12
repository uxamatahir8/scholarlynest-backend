<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class SystemPermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        ['name' => 'magazines.view-any', 'module' => 'magazines', 'description' => 'View any magazine'],
        ['name' => 'magazines.view-own', 'module' => 'magazines', 'description' => 'View own magazines'],
        ['name' => 'magazines.create', 'module' => 'magazines', 'description' => 'Create magazines'],
        ['name' => 'magazines.edit', 'module' => 'magazines', 'description' => 'Edit magazines'],
        ['name' => 'magazines.delete', 'module' => 'magazines', 'description' => 'Delete magazines'],
        ['name' => 'articles.view-any', 'module' => 'articles', 'description' => 'View any article'],
        ['name' => 'articles.view-own', 'module' => 'articles', 'description' => 'View own articles'],
        ['name' => 'articles.create', 'module' => 'articles', 'description' => 'Create articles'],
        ['name' => 'articles.edit-any', 'module' => 'articles', 'description' => 'Edit any article'],
        ['name' => 'articles.edit-own', 'module' => 'articles', 'description' => 'Edit own articles'],
        ['name' => 'articles.delete-any', 'module' => 'articles', 'description' => 'Delete any article'],
        ['name' => 'articles.delete-own', 'module' => 'articles', 'description' => 'Delete own articles'],
        ['name' => 'articles.approve', 'module' => 'articles', 'description' => 'Approve or reject articles'],
        ['name' => 'articles.auto-approve', 'module' => 'articles', 'description' => 'Auto-approve eligible articles'],
        ['name' => 'articles.manage-assets', 'module' => 'articles', 'description' => 'Allow adding assets to articles'],
        ['name' => 'roles.view-any', 'module' => 'roles', 'description' => 'View roles and permissions'],
        ['name' => 'roles.manage', 'module' => 'roles', 'description' => 'Manage system roles'],
        ['name' => 'users.view-any', 'module' => 'users', 'description' => 'View list of users'],
        ['name' => 'users.manage', 'module' => 'users', 'description' => 'Manage user roles and statuses'],
        ['name' => 'users.create', 'module' => 'users', 'description' => 'Create new administrative users'],
        ['name' => 'settings.view-any', 'module' => 'settings', 'description' => 'View global application settings'],
        ['name' => 'settings.manage', 'module' => 'settings', 'description' => 'Manage and edit settings configuration'],
        ['name' => 'footer.manage', 'module' => 'footer', 'description' => 'Manage footer content'],
        ['name' => 'newsletters.view-any', 'module' => 'newsletters', 'description' => 'View newsletter campaigns and subscribers'],
        ['name' => 'newsletters.create', 'module' => 'newsletters', 'description' => 'Create newsletter campaigns'],
        ['name' => 'newsletters.send', 'module' => 'newsletters', 'description' => 'Send newsletter campaigns'],
        ['name' => 'newsletters.delete', 'module' => 'newsletters', 'description' => 'Delete newsletter campaigns'],
        ['name' => 'seo.articles', 'module' => 'seo', 'description' => 'Manage SEO fields for articles'],
        ['name' => 'seo.magazines', 'module' => 'seo', 'description' => 'Manage SEO fields for magazines'],
        ['name' => 'seo.cms-pages', 'module' => 'seo', 'description' => 'Manage SEO fields for CMS pages'],
        ['name' => 'support_ticket_management', 'module' => 'support', 'description' => 'Manage support tickets'],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                [
                    'module' => $permission['module'],
                    'description' => $permission['description'],
                ]
            );
        }

        $allPermissionIds = Permission::pluck('id');
        Role::whereIn('name', ['super_admin', 'admin'])->get()
            ->each(fn (Role $role) => $role->permissions()->sync($allPermissionIds));

        $this->syncRole('author', [
            'magazines.view-any',
            'articles.view-own',
            'articles.create',
            'articles.edit-own',
            'articles.manage-assets',
            'seo.articles',
        ]);
        foreach (['super_editor', 'magazine_editor', 'journal_editor'] as $editorRole) {
            $this->syncRole($editorRole, [
            'magazines.view-own',
            'magazines.edit',
            'articles.view-own',
            'articles.approve',
            'articles.manage-assets',
            'seo.articles',
            ]);
        }
        $this->syncRole('sub_editor', [
            'magazines.view-own',
            'articles.view-own',
            'articles.manage-assets',
        ]);
        $this->syncRole('reviewer', [
            'articles.view-own',
            'articles.manage-assets',
        ]);
        $this->syncRole('publisher', [
            'magazines.view-own',
            'articles.view-own',
            'articles.approve',
            'seo.articles',
        ]);
        $this->syncRole('copy_editor', [
            'articles.view-own',
            'articles.manage-assets',
        ]);
        $this->syncRole('proofreader', [
            'articles.view-own',
            'articles.manage-assets',
        ]);
    }

    private function syncRole(string $roleName, array $permissionNames): void
    {
        $role = Role::where('name', $roleName)->first();
        if (!$role) {
            return;
        }

        $role->permissions()->sync(Permission::whereIn('name', $permissionNames)->pluck('id'));
    }
}
