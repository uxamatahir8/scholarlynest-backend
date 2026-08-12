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
        $permission = Permission::firstOrCreate([
            'name' => 'articles.auto-approve',
            'module' => 'articles',
            'description' => 'Auto-Approve & Compile PDF'
        ]);

        // Assign to super admin by default so they have it initially
        $superAdmin = Role::where('name', 'super_admin')->first();
        if ($superAdmin && !$superAdmin->permissions()->where('name', 'articles.auto-approve')->exists()) {
            $superAdmin->permissions()->attach($permission->id);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Permission::where('name', 'articles.auto-approve')->delete();
    }
};
