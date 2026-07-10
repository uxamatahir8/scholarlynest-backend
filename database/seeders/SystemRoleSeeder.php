<?php

namespace Database\Seeders;

use App\Constants\SystemRoles;
use App\Models\Role;
use Illuminate\Database\Seeder;

class SystemRoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SystemRoles::DEFINITIONS as $name => $definition) {
            Role::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $definition['display_name'],
                    'description' => $definition['description'],
                    'is_system' => true,
                ]
            );
        }
    }
}
