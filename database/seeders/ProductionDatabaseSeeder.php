<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ProductionDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SystemRoleSeeder::class,
            SystemPermissionSeeder::class,
            RequiredSystemSettingsSeeder::class,
            DefaultSuperAdminSeeder::class,
            FooterManagementSeeder::class,
            ReviewerEvaluationQuestionnaireSeeder::class,
        ]);
    }
}
