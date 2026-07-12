<?php

namespace Database\Seeders;

use App\Constants\SystemRoles;
use App\Models\Role;
use Illuminate\Database\Seeder;

class SystemRoleSeeder extends Seeder
{
    public function run(): void
    {
        $legacyEditor = Role::where('name', 'editor')->first();
        $superEditor = Role::where('name', 'super_editor')->first();
        if ($legacyEditor && !$superEditor) {
            $legacyEditor->update([
                'name' => 'super_editor',
                'display_name' => 'Super Editor',
                'description' => SystemRoles::DEFINITIONS['super_editor']['description'],
                'is_system' => true,
            ]);
        } elseif ($legacyEditor && $superEditor) {
            \App\Models\User::where('role_id', $legacyEditor->id)->update(['role_id' => $superEditor->id]);
            $legacyEditor->delete();
        }

        foreach (SystemRoles::DEFINITIONS as $name => $definition) {
            if ($name === 'editor') {
                continue;
            }
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
