<?php

namespace Tests\Feature;

use App\Constants\SystemRoles;
use App\Models\Article;
use App\Models\Magazine;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProductionDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductionDatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        putenv('DEFAULT_SUPER_ADMIN_NAME=Seeded Admin');
        putenv('DEFAULT_SUPER_ADMIN_EMAIL=seeded.admin@example.test');
        putenv('DEFAULT_SUPER_ADMIN_PASSWORD=LocalSeedPassword123!');
        putenv('DEFAULT_SUPER_ADMIN_RESET_PASSWORD=false');
    }

    protected function tearDown(): void
    {
        putenv('DEFAULT_SUPER_ADMIN_NAME');
        putenv('DEFAULT_SUPER_ADMIN_EMAIL');
        putenv('DEFAULT_SUPER_ADMIN_PASSWORD');
        putenv('DEFAULT_SUPER_ADMIN_RESET_PASSWORD');

        parent::tearDown();
    }

    public function test_default_database_seeder_runs_production_safe_baseline_only(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertEqualsCanonicalizing(
            array_keys(SystemRoles::DEFINITIONS),
            Role::pluck('name')->all()
        );
        $this->assertSame(1, User::count());
        $this->assertDatabaseHas('users', [
            'email' => 'seeded.admin@example.test',
            'name' => 'Seeded Admin',
        ]);
        $this->assertSame(0, Article::count());
        $this->assertSame(0, Magazine::count());
        $this->assertSame(1, \DB::table('review_questionnaires')->count());
        $this->assertDatabaseHas('review_questionnaires', [
            'name' => \Database\Seeders\ReviewerEvaluationQuestionnaireSeeder::TEMPLATE_NAME,
            'is_active' => true,
        ]);
        $this->assertGreaterThan(0, Permission::count());
        $this->assertDatabaseHas('settings', ['key' => 'default_registration_role', 'value' => 'author']);
    }

    public function test_production_database_seeder_is_idempotent_and_preserves_existing_password(): void
    {
        $this->seed(ProductionDatabaseSeeder::class);
        $admin = User::where('email', 'seeded.admin@example.test')->firstOrFail();
        $admin->update(['password' => Hash::make('ExistingPassword123!')]);

        $counts = [
            'users' => User::count(),
            'roles' => Role::count(),
            'permissions' => Permission::count(),
            'settings' => Setting::count(),
            'articles' => Article::count(),
            'magazines' => Magazine::count(),
            'questionnaires' => \DB::table('review_questionnaires')->count(),
            'questionnaire_versions' => \DB::table('review_questionnaire_versions')->count(),
            'review_questions' => \DB::table('review_questions')->count(),
            'review_question_options' => \DB::table('review_question_options')->count(),
        ];

        $this->seed(ProductionDatabaseSeeder::class);
        $admin->refresh();

        $this->assertSame($counts, [
            'users' => User::count(),
            'roles' => Role::count(),
            'permissions' => Permission::count(),
            'settings' => Setting::count(),
            'articles' => Article::count(),
            'magazines' => Magazine::count(),
            'questionnaires' => \DB::table('review_questionnaires')->count(),
            'questionnaire_versions' => \DB::table('review_questionnaire_versions')->count(),
            'review_questions' => \DB::table('review_questions')->count(),
            'review_question_options' => \DB::table('review_question_options')->count(),
        ]);
        $this->assertTrue(Hash::check('ExistingPassword123!', $admin->password));
        $this->assertFalse(Hash::check('LocalSeedPassword123!', $admin->password));
    }
}
