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
        $permissions = [
            ['name' => 'footer.manage', 'module' => 'settings', 'description' => 'Manage and edit global footer categories and pages'],
        ];

        $insertedPermissionIds = [];

        foreach ($permissions as $p) {
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
        Permission::where('name', 'footer.manage')->delete();
    }
};
