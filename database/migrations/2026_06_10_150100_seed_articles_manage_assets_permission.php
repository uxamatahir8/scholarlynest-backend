<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Permission;
use App\Models\Role;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => 'articles.manage-assets',
            'module' => 'articles',
            'description' => 'Allow adding assets to articles'
        ]);

        // Assign to all existing roles by default
        $roles = Role::all();
        foreach ($roles as $role) {
            if (!$role->permissions()->where('name', 'articles.manage-assets')->exists()) {
                $role->permissions()->attach($permission->id);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Permission::where('name', 'articles.manage-assets')->delete();
    }
};
