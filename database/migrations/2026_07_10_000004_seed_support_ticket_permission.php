<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::updateOrCreate(
            ['name' => 'support_ticket_management'],
            ['module' => 'support', 'description' => 'Manage support tickets']
        );

        Role::whereIn('name', ['super_admin', 'admin'])->get()
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]));
    }

    public function down(): void
    {
        Permission::where('name', 'support_ticket_management')->delete();
    }
};
