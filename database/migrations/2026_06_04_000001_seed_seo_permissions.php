<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Permission;
use App\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $seoPermissions = [
            ['name' => 'seo.articles',   'module' => 'seo', 'description' => 'Manage SEO fields for articles'],
            ['name' => 'seo.magazines',  'module' => 'seo', 'description' => 'Manage SEO fields for magazines'],
            ['name' => 'seo.cms-pages',  'module' => 'seo', 'description' => 'Manage SEO fields for CMS pages'],
        ];

        $insertedPermissionIds = [];

        foreach ($seoPermissions as $p) {
            $permission = Permission::updateOrCreate(
                ['name' => $p['name']],
                ['module' => $p['module'], 'description' => $p['description']]
            );
            $insertedPermissionIds[] = $permission->id;
        }

        // Attach permissions to super_admin role
        $superAdminRole = Role::where('name', 'super_admin')->first();
        if ($superAdminRole) {
            $superAdminRole->permissions()->syncWithoutDetaching($insertedPermissionIds);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $seoPermissionNames = ['seo.articles', 'seo.magazines', 'seo.cms-pages'];
        Permission::whereIn('name', $seoPermissionNames)->delete();
    }
};
