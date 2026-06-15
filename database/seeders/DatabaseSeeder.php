<?php

namespace Database\Seeders;

use App\Constants\SystemRoles;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        DB::beginTransaction();

        try {
            // 1. Temporarily disable foreign key constraints
            DB::statement('SET FOREIGN_KEY_CHECKS = 0');

            // 2. Truncate historical tables
            $tables = [
                'article_tag',
                'tags',
                'article_share_clicks',
                'articles',
                'magazine_pages',
                'magazines',
                'audit_logs',
                'sessions',
                'password_reset_tokens',
                'users',
                'settings',
                'permission_role',
                'roles',
                'permissions',
                'faqs',
                'footer_pages',
                'footer_categories',
                'contact_subjects',
                'magazine_user'
            ];

            foreach ($tables as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    DB::table($table)->truncate();
                }
            }

            // Re-enable foreign key constraints
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');

            // ==========================================
            // 3. SEED ROLES
            // ==========================================
            $roles = collect(SystemRoles::DEFINITIONS)
                ->mapWithKeys(fn (array $definition, string $name) => [
                    $name => Role::create([
                        'name' => $name,
                        'display_name' => $definition['display_name'],
                        'description' => $definition['description'],
                        'is_system' => true,
                    ]),
                ]);

            $superAdminRole = $roles->get('super_admin');
            $authorRole = $roles->get('author');
            $editorRole = $roles->get('editor');
            $subEditorRole = $roles->get('sub_editor');
            $reviewerRole = $roles->get('reviewer');
            $publisherRole = $roles->get('publisher');
            $copyEditorRole = $roles->get('copy_editor');
            $proofreaderRole = $roles->get('proofreader');
            
            // ==========================================
            // 4. SEED GRANULAR PERMISSIONS
            // ==========================================
            $permissionsData = [
                // Magazines module
                ['name' => 'magazines.view-any', 'module' => 'magazines', 'description' => 'View any magazine'],
                ['name' => 'magazines.view-own', 'module' => 'magazines', 'description' => 'View own magazines'],
                ['name' => 'magazines.create', 'module' => 'magazines', 'description' => 'Create magazines'],
                ['name' => 'magazines.edit', 'module' => 'magazines', 'description' => 'Edit magazines'],
                ['name' => 'magazines.delete', 'module' => 'magazines', 'description' => 'Delete magazines'],

                // Articles module
                ['name' => 'articles.view-any', 'module' => 'articles', 'description' => 'View any article'],
                ['name' => 'articles.view-own', 'module' => 'articles', 'description' => 'View own articles'],
                ['name' => 'articles.create', 'module' => 'articles', 'description' => 'Create articles'],
                ['name' => 'articles.edit-any', 'module' => 'articles', 'description' => 'Edit any article'],
                ['name' => 'articles.edit-own', 'module' => 'articles', 'description' => 'Edit own articles'],
                ['name' => 'articles.delete-any', 'module' => 'articles', 'description' => 'Delete any article'],
                ['name' => 'articles.delete-own', 'module' => 'articles', 'description' => 'Delete own articles'],
                ['name' => 'articles.approve', 'module' => 'articles', 'description' => 'Approve or reject articles'],
                ['name' => 'articles.manage-assets', 'module' => 'articles', 'description' => 'Allow adding assets to articles'],

                // Roles module
                ['name' => 'roles.view-any', 'module' => 'roles', 'description' => 'View roles and permissions'],
                ['name' => 'roles.manage', 'module' => 'roles', 'description' => 'Manage system roles'],

                // Users module
                ['name' => 'users.view-any', 'module' => 'users', 'description' => 'View list of users'],
                ['name' => 'users.manage', 'module' => 'users', 'description' => 'Manage user roles and statuses'],
                ['name' => 'users.create', 'module' => 'users', 'description' => 'Create new administrative users'],

                // Settings module
                ['name' => 'settings.view-any', 'module' => 'settings', 'description' => 'View global application settings'],
                ['name' => 'settings.manage', 'module' => 'settings', 'description' => 'Manage and edit settings configuration'],

                // Newsletter module
                ['name' => 'newsletters.view-any', 'module' => 'newsletters', 'description' => 'View newsletter campaigns and subscribers'],
                ['name' => 'newsletters.create', 'module' => 'newsletters', 'description' => 'Create newsletter campaigns'],
                ['name' => 'newsletters.send', 'module' => 'newsletters', 'description' => 'Send newsletter campaigns'],
                ['name' => 'newsletters.delete', 'module' => 'newsletters', 'description' => 'Delete newsletter campaigns'],

                // SEO module
                ['name' => 'seo.articles', 'module' => 'seo', 'description' => 'Manage SEO fields for articles'],
                ['name' => 'seo.magazines', 'module' => 'seo', 'description' => 'Manage SEO fields for magazines'],
                ['name' => 'seo.cms-pages', 'module' => 'seo', 'description' => 'Manage SEO fields for CMS pages'],
            ];

            foreach ($permissionsData as $p) {
                Permission::firstOrCreate(['name' => $p['name']], $p);
            }

            $allPermissions = Permission::all();

            // ==========================================
            // 5. ASSOCIATE PERMISSIONS TO ROLES
            // ==========================================
            // Super Admin gets all permissions
            $superAdminRole->permissions()->sync($allPermissions->pluck('id'));

            // Author gets limited permissions for own records and submitting articles
            $authorPermissions = Permission::whereIn('name', [
                'magazines.view-any',
                'articles.view-own',
                'articles.create',
                'articles.edit-own',
                'articles.delete-own',
                'seo.articles',
                'articles.manage-assets',
            ])->get();
            $authorRole->permissions()->sync($authorPermissions->pluck('id'));

            // Editor gets permissions for managing articles in assigned magazines.
            $editorPermissions = Permission::whereIn('name', [
                'magazines.view-any',
                'articles.view-own',
                'articles.edit-own',
                'articles.approve',
                'articles.manage-assets',
                'seo.articles',
            ])->get();
            $editorRole->permissions()->sync($editorPermissions->pluck('id'));

            $subEditorRole->permissions()->sync(Permission::whereIn('name', [
                'magazines.view-own',
                'articles.view-own',
                'articles.edit-own',
                'articles.manage-assets',
            ])->pluck('id'));

            $reviewerRole->permissions()->sync(Permission::whereIn('name', [
                'articles.view-own',
                'articles.edit-own',
                'articles.manage-assets',
            ])->pluck('id'));

            $publisherRole->permissions()->sync(Permission::whereIn('name', [
                'magazines.view-own',
                'articles.view-own',
                'articles.edit-own',
                'articles.approve',
                'seo.articles',
            ])->pluck('id'));

            $copyEditorRole->permissions()->sync(Permission::whereIn('name', [
                'articles.view-own',
                'articles.edit-own',
                'articles.manage-assets',
            ])->pluck('id'));

            $proofreaderRole->permissions()->sync(Permission::whereIn('name', [
                'articles.view-own',
                'articles.edit-own',
                'articles.manage-assets',
            ])->pluck('id'));

            // ==========================================
            // 6. SEED DEFAULT USER ACCOUNTS
            // ==========================================
            $superAdminUser = User::create([
                'name' => 'Super Admin',
                'email' => 'info@scholarlynest.com',
                'password' => Hash::make('admin12345'),
                'email_verified_at' => now(),
                'role_id' => $superAdminRole->id,
            ]);

            // ==========================================
            // 7. SEED DEFAULT SYSTEM CONFIGURATION
            // ==========================================
            Setting::create([
                'key' => 'default_registration_role',
                'value' => 'author',
            ]);

            // ==========================================
            // 8. CALL MODULE SEEDERS
            // ==========================================
            $this->call(CmsPageSeeder::class);
            $this->call(EditorialBoardSeeder::class);
            $this->call(MagazineSeeder::class);
            $this->call(FaqSeeder::class);
            $this->call(FooterManagementSeeder::class);
            $this->call(ContactSubjectSeeder::class);

            try {
                if (DB::transactionLevel() > 0) {
                    DB::commit();
                }
            } catch (\PDOException $pdoEx) {
                if (strpos($pdoEx->getMessage(), 'active transaction') === false) {
                    throw $pdoEx;
                }
            }
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            // Re-enable foreign key constraints in case of failure
            try {
                DB::statement('SET FOREIGN_KEY_CHECKS = 1');
            } catch (\Exception $ex) {
                // Ignore nested database exception
            }
            throw $e;
        }
    }
}
