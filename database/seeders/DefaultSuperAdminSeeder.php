<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DefaultSuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $name = env('DEFAULT_SUPER_ADMIN_NAME') ?: 'Super Admin';
        $email = env('DEFAULT_SUPER_ADMIN_EMAIL');
        $password = env('DEFAULT_SUPER_ADMIN_PASSWORD');
        $shouldResetPassword = filter_var(env('DEFAULT_SUPER_ADMIN_RESET_PASSWORD', false), FILTER_VALIDATE_BOOLEAN);

        if (App::environment('production') && (!$email || !$password)) {
            throw new RuntimeException('DEFAULT_SUPER_ADMIN_EMAIL and DEFAULT_SUPER_ADMIN_PASSWORD are required when seeding production.');
        }

        $email = $email ?: 'superadmin@example.test';
        $password = $password ?: 'LocalDevPassword123!';

        $role = Role::where('name', 'super_admin')->firstOrFail();
        $user = User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();

        if (!$user) {
            User::create([
                'name' => $name,
                'email' => strtolower($email),
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'role_id' => $role->id,
                'needs_password_reset' => false,
                'current_email_verified' => true,
            ]);
            return;
        }

        $updates = [
            'name' => $user->name ?: $name,
            'role_id' => $role->id,
            'email_verified_at' => $user->email_verified_at ?: now(),
            'current_email_verified' => true,
        ];

        if ($shouldResetPassword) {
            $updates['password'] = Hash::make($password);
            $updates['needs_password_reset'] = false;
        }

        $user->update($updates);
    }
}
