<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::updateOrCreate(
            ['name' => 'notifications.delivery.manage'],
            ['module' => 'notifications', 'description' => 'Inspect and retry notification email deliveries']
        );

        Role::whereIn('name', ['super_admin', 'admin'])->get()
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]));
    }

    public function down(): void
    {
        Permission::where('name', 'notifications.delivery.manage')->delete();
    }
};
