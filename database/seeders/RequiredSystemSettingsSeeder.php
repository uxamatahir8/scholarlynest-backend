<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class RequiredSystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        Setting::updateOrCreate(['key' => 'default_registration_role'], ['value' => 'author']);
        Setting::updateOrCreate(['key' => 'registration_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['key' => 'registration_notice'], ['value' => 'Create an author account to submit manuscripts.']);
    }
}
