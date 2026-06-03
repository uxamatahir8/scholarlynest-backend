<?php

namespace Database\Seeders;

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
                'faqs'
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
            $superAdminRole = Role::create([
                'name' => 'super_admin',
                'display_name' => 'Super Admin',
                'is_system' => true,
            ]);

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
            ];

            foreach ($permissionsData as $p) {
                Permission::create($p);
            }

            $allPermissions = Permission::all();

            // ==========================================
            // 5. ASSOCIATE PERMISSIONS TO ROLES
            // ==========================================
            // Super Admin gets all permissions
            $superAdminRole->permissions()->sync($allPermissions->pluck('id'));

            // ==========================================
            // 6. SEED DEFAULT USER ACCOUNTS
            // ==========================================
            $superAdminUser = User::create([
                'name' => 'Dr. Evelyn Reed (Admin)',
                'email' => 'admin@scholarlynest.com',
                'password' => Hash::make('admin12345'),
                'email_verified_at' => now(),
                'role_id' => $superAdminRole->id,
            ]);

            // ==========================================
            // 7. SEED DEFAULT SYSTEM CONFIGURATION
            // ==========================================
            Setting::create([
                'key' => 'default_registration_role',
                'value' => 'super_admin',
            ]);

            // ==========================================
            // 8. CALL MODULE SEEDERS
            // ==========================================
            $this->call(CmsPageSeeder::class);
            $this->call(EditorialBoardSeeder::class);
            $this->call(MagazineSeeder::class);
            $this->call(FaqSeeder::class);

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
