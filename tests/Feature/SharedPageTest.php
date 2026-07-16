<?php

namespace Tests\Feature;

use App\Models\Magazine;
use App\Models\MagazinePage;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SharedPublicPage;
use App\Models\User;
use Database\Seeders\SystemPermissionSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SharedPageTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Magazine $magazine;

    private Magazine $otherMagazine;

    private Magazine $journal;

    private Magazine $otherJournal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemRoleSeeder::class);
        $this->seed(SystemPermissionSeeder::class);

        $this->superAdmin = $this->user('super_admin');
        $this->magazine = $this->publication('Shared Test Magazine', Magazine::TYPE_MAGAZINE);
        $this->otherMagazine = $this->publication('Other Shared Test Magazine', Magazine::TYPE_MAGAZINE);
        $this->journal = $this->publication('Shared Test Journal', Magazine::TYPE_JOURNAL);
        $this->otherJournal = $this->publication('Other Shared Test Journal', Magazine::TYPE_JOURNAL);
    }

    public function test_super_admin_can_create_shared_page_for_all_magazines(): void
    {
        $this->createAsAdmin(SharedPublicPage::SCOPE_ALL_MAGAZINES)
            ->assertCreated()
            ->assertJsonPath('page.target_scope', SharedPublicPage::SCOPE_ALL_MAGAZINES);
    }

    public function test_super_admin_can_create_shared_page_for_selected_magazines(): void
    {
        $this->createAsAdmin(SharedPublicPage::SCOPE_SELECTED_MAGAZINES, [
            'selected_magazine_ids' => [$this->magazine->id],
        ])->assertCreated()->assertJsonCount(1, 'page.targets');
    }

    public function test_super_admin_can_create_shared_page_for_all_journals(): void
    {
        $this->createAsAdmin(SharedPublicPage::SCOPE_ALL_JOURNALS)
            ->assertCreated()
            ->assertJsonPath('page.target_scope', SharedPublicPage::SCOPE_ALL_JOURNALS);
    }

    public function test_super_admin_can_create_shared_page_for_selected_journals(): void
    {
        $this->createAsAdmin(SharedPublicPage::SCOPE_SELECTED_JOURNALS, [
            'selected_journal_ids' => [$this->journal->id],
        ])->assertCreated()->assertJsonCount(1, 'page.targets');
    }

    public function test_super_admin_can_create_shared_page_for_custom_magazine_and_journal_selection(): void
    {
        $this->createAsAdmin(SharedPublicPage::SCOPE_CUSTOM, [
            'selected_magazine_ids' => [$this->magazine->id],
            'selected_journal_ids' => [$this->journal->id],
        ])->assertCreated()->assertJsonCount(2, 'page.targets');
    }

    public function test_non_admin_user_cannot_manage_shared_pages(): void
    {
        Sanctum::actingAs($this->user('magazine_editor'));

        $this->getJson('/api/admin/shared-pages')->assertForbidden();
        $this->postJson('/api/admin/shared-pages', $this->payload(SharedPublicPage::SCOPE_ALL_MAGAZINES))->assertForbidden();
    }

    public function test_selected_magazine_targets_reject_journal_ids(): void
    {
        $this->createAsAdmin(SharedPublicPage::SCOPE_SELECTED_MAGAZINES, [
            'selected_magazine_ids' => [$this->journal->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('selected_magazine_ids');
    }

    public function test_selected_journal_targets_reject_magazine_ids(): void
    {
        $this->createAsAdmin(SharedPublicPage::SCOPE_SELECTED_JOURNALS, [
            'selected_journal_ids' => [$this->magazine->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('selected_journal_ids');
    }

    public function test_active_all_magazines_shared_page_appears_on_magazine_public_page(): void
    {
        $page = $this->sharedPage(SharedPublicPage::SCOPE_ALL_MAGAZINES);

        $this->publicPage($this->magazine, $page)->assertOk()->assertJsonPath('page.is_shared', true);
    }

    public function test_active_all_magazines_shared_page_does_not_appear_on_journal_public_page(): void
    {
        $page = $this->sharedPage(SharedPublicPage::SCOPE_ALL_MAGAZINES);

        $this->publicPage($this->journal, $page)->assertNotFound();
    }

    public function test_active_all_journals_shared_page_appears_on_journal_public_page(): void
    {
        $page = $this->sharedPage(SharedPublicPage::SCOPE_ALL_JOURNALS);

        $this->publicPage($this->journal, $page)->assertOk()->assertJsonPath('page.is_shared', true);
    }

    public function test_active_all_journals_shared_page_does_not_appear_on_magazine_public_page(): void
    {
        $page = $this->sharedPage(SharedPublicPage::SCOPE_ALL_JOURNALS);

        $this->publicPage($this->magazine, $page)->assertNotFound();
    }

    public function test_custom_selected_shared_page_appears_only_on_selected_publications(): void
    {
        $page = $this->sharedPage(SharedPublicPage::SCOPE_CUSTOM);
        $page->targets()->createMany([
            ['publication_id' => $this->magazine->id, 'publication_type' => Magazine::TYPE_MAGAZINE],
            ['publication_id' => $this->journal->id, 'publication_type' => Magazine::TYPE_JOURNAL],
        ]);

        $this->publicPage($this->magazine, $page)->assertOk();
        $this->publicPage($this->journal, $page)->assertOk();
        $this->publicPage($this->otherMagazine, $page)->assertNotFound();
        $this->publicPage($this->otherJournal, $page)->assertNotFound();
    }

    public function test_draft_and_inactive_shared_pages_do_not_appear_publicly(): void
    {
        foreach (['draft', 'inactive'] as $status) {
            $page = $this->sharedPage(SharedPublicPage::SCOPE_ALL_PUBLICATIONS, $status);
            $this->publicPage($this->magazine, $page)->assertNotFound();
        }
    }

    public function test_publication_specific_page_overrides_shared_page_with_same_slug(): void
    {
        $shared = $this->sharedPage(SharedPublicPage::SCOPE_ALL_MAGAZINES);
        $specific = MagazinePage::create([
            'magazine_id' => $this->magazine->id,
            'title' => 'Specific Policy',
            'slug' => $shared->slug,
            'content' => '<p>Specific content</p>',
            'status' => 'active',
        ]);

        $this->publicPage($this->magazine, $shared)
            ->assertOk()
            ->assertJsonPath('page.id', $specific->id)
            ->assertJsonPath('page.is_shared', false)
            ->assertJsonPath('page.content', '<p>Specific content</p>');
    }

    public function test_shared_page_navigation_appears_only_for_targeted_publications(): void
    {
        $visible = $this->sharedPage(SharedPublicPage::SCOPE_SELECTED_MAGAZINES);
        $visible->targets()->create([
            'publication_id' => $this->magazine->id,
            'publication_type' => Magazine::TYPE_MAGAZINE,
        ]);
        $hidden = $this->sharedPage(SharedPublicPage::SCOPE_ALL_MAGAZINES);
        $hidden->update(['show_in_navigation' => false]);

        $this->publicShell($this->magazine)->assertOk()->assertJsonFragment(['slug' => $visible->slug, 'is_shared' => true]);
        $this->publicShell($this->otherMagazine)->assertOk()->assertJsonMissing(['slug' => $visible->slug]);
        $this->publicShell($this->magazine)->assertJsonMissing(['slug' => $hidden->slug]);
    }

    public function test_shared_page_permission_seeder_is_idempotent(): void
    {
        $this->seed(SystemPermissionSeeder::class);
        $this->seed(SystemPermissionSeeder::class);

        $this->assertSame(1, Permission::where('name', 'shared_pages.manage')->count());
        foreach (['super_admin', 'admin'] as $role) {
            $this->assertTrue(Role::where('name', $role)->firstOrFail()->permissions()->where('name', 'shared_pages.manage')->exists());
        }
    }

    private function createAsAdmin(string $scope, array $overrides = [])
    {
        Sanctum::actingAs($this->superAdmin);

        return $this->postJson('/api/admin/shared-pages', $this->payload($scope, $overrides));
    }

    private function payload(string $scope, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Shared '.$scope,
            'slug' => 'shared-'.str_replace('_', '-', $scope),
            'content' => '<p>Shared policy content</p>',
            'status' => 'active',
            'target_scope' => $scope,
            'show_in_navigation' => true,
            'sort_order' => 2,
            'seo_title' => null,
            'seo_description' => null,
            'selected_magazine_ids' => [],
            'selected_journal_ids' => [],
        ], $overrides);
    }

    private function sharedPage(string $scope, string $status = 'active'): SharedPublicPage
    {
        static $sequence = 0;
        $sequence++;

        return SharedPublicPage::create([
            'title' => "Shared Page {$sequence}",
            'slug' => "shared-page-{$sequence}",
            'content' => '<p>Shared public content</p>',
            'status' => $status,
            'target_scope' => $scope,
            'show_in_navigation' => true,
            'sort_order' => $sequence,
            'created_by' => $this->superAdmin->id,
        ]);
    }

    private function publicPage(Magazine $publication, SharedPublicPage $page)
    {
        $prefix = $publication->isJournal() ? 'journals' : 'magazines';

        return $this->getJson("/api/{$prefix}/{$publication->slug}/pages/{$page->slug}");
    }

    private function publicShell(Magazine $publication)
    {
        $prefix = $publication->isJournal() ? 'journals' : 'magazines';

        return $this->getJson("/api/{$prefix}/{$publication->slug}");
    }

    private function publication(string $title, string $type): Magazine
    {
        return Magazine::create([
            'title' => $title,
            'slug' => str($title)->slug(),
            'publication_type' => $type,
            'status' => 'active',
        ]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
    }
}
