<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\Magazine;
use App\Models\MagazinePage;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\SystemPermissionSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicationEditorRoleTest extends TestCase
{
    use RefreshDatabase;

    private Magazine $magazine;
    private Magazine $journal;
    private Article $magazineArticle;
    private Article $journalArticle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemRoleSeeder::class);
        $this->seed(SystemPermissionSeeder::class);

        $author = User::factory()->create(['role_id' => Role::where('name', 'author')->value('id')]);
        $this->magazine = Magazine::create(['title' => 'Scoped Magazine', 'slug' => 'scoped-magazine', 'publication_type' => 'magazine']);
        $this->journal = Magazine::create(['title' => 'Scoped Journal', 'slug' => 'scoped-journal', 'publication_type' => 'journal']);
        $this->magazineArticle = $this->article($author, $this->magazine, 'Magazine Article');
        $this->journalArticle = $this->article($author, $this->journal, 'Journal Article');
    }

    public function test_role_seed_creates_publication_editors_and_is_idempotent(): void
    {
        $this->seed(SystemRoleSeeder::class);
        $this->assertSame(1, Role::where('name', 'super_editor')->count());
        $this->assertSame(1, Role::where('name', 'magazine_editor')->count());
        $this->assertSame(1, Role::where('name', 'journal_editor')->count());
        $this->assertSame('Super Editor', Role::where('name', 'super_editor')->value('display_name'));
    }

    public function test_magazine_and_journal_editors_are_scoped_to_their_assigned_type(): void
    {
        $magazineEditor = $this->editor('magazine_editor', [$this->magazine, $this->journal]);
        Sanctum::actingAs($magazineEditor);
        $this->getJson('/api/admin/articles')->assertOk()
            ->assertJsonFragment(['title' => 'Magazine Article'])
            ->assertJsonMissing(['title' => 'Journal Article']);
        $this->getJson("/api/admin/articles/{$this->journalArticle->id}")->assertForbidden();

        $journalEditor = $this->editor('journal_editor', [$this->magazine, $this->journal]);
        Sanctum::actingAs($journalEditor);
        $this->getJson('/api/admin/articles')->assertOk()
            ->assertJsonFragment(['title' => 'Journal Article'])
            ->assertJsonMissing(['title' => 'Magazine Article']);
        $this->getJson("/api/admin/articles/{$this->magazineArticle->id}")->assertForbidden();
    }

    public function test_super_editor_sees_both_assigned_types_and_filter_payload_fields(): void
    {
        $editor = $this->editor('super_editor', [$this->magazine, $this->journal]);
        Sanctum::actingAs($editor);

        $this->getJson('/api/admin/articles?publication_type=journal')->assertOk()
            ->assertJsonFragment([
                'title' => 'Journal Article',
                'publication_type' => 'journal',
                'publication_label' => 'Journal',
                'publication_name' => 'Scoped Journal',
            ])
            ->assertJsonMissing(['title' => 'Magazine Article']);
    }

    public function test_magazine_assignment_options_includes_publication_type(): void
    {
        $admin = User::factory()->create(['role_id' => Role::where('name', 'super_admin')->value('id')]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/users/magazine-assignment-options')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $this->magazine->id,
                'title' => 'Scoped Magazine',
                'publication_type' => 'magazine',
            ])
            ->assertJsonFragment([
                'id' => $this->journal->id,
                'title' => 'Scoped Journal',
                'publication_type' => 'journal',
            ]);
    }

    public function test_editor_transfer_access_rejects_wrong_publication_type(): void
    {
        $editor = $this->editor('magazine_editor', [$this->magazine, $this->journal]);
        Sanctum::actingAs($editor);

        $this->getJson("/api/articles/{$this->magazineArticle->id}/transfer-target-magazines")->assertOk();
        $this->getJson("/api/articles/{$this->journalArticle->id}/transfer-target-magazines")->assertForbidden();
    }

    public function test_new_publication_editor_roles_can_access_their_sub_editor_desks(): void
    {
        foreach ([
            'super_editor' => [$this->magazine, $this->journal],
            'magazine_editor' => [$this->magazine],
            'journal_editor' => [$this->journal],
        ] as $role => $publications) {
            $editor = $this->editor($role, $publications);
            Sanctum::actingAs($editor);

            $this->getJson('/api/admin/editor/sub-editors')->assertOk();
        }
    }

    public function test_super_admin_can_assign_sub_editors_to_each_new_editor_role(): void
    {
        $admin = User::factory()->create(['role_id' => Role::where('name', 'super_admin')->value('id')]);
        $subEditorRoleId = Role::where('name', 'sub_editor')->value('id');

        foreach ([
            'super_editor' => [$this->magazine, $this->journal],
            'magazine_editor' => [$this->magazine],
            'journal_editor' => [$this->journal],
        ] as $role => $publications) {
            $editor = $this->editor($role, $publications);
            Sanctum::actingAs($admin);

            $response = $this->postJson('/api/admin/users', [
                'name' => str($role)->headline().' Sub Editor',
                'email' => $role.'-sub@example.com',
                'role_id' => $subEditorRoleId,
                'status' => 'active',
                'editor_ids' => [$editor->id],
            ]);

            $response->assertCreated();
            $this->assertDatabaseHas('editor_sub_editor', [
                'editor_id' => $editor->id,
                'sub_editor_id' => $response->json('data.id'),
            ]);
        }
    }

    public function test_publication_editor_page_access_respects_publication_type(): void
    {
        $magazineEditor = $this->editor('magazine_editor', [$this->magazine, $this->journal]);
        Sanctum::actingAs($magazineEditor);
        $this->getJson('/api/admin/magazines/'.$this->magazine->slug)->assertOk();
        $this->getJson('/api/admin/journals/'.$this->journal->slug)->assertForbidden();

        $journalEditor = $this->editor('journal_editor', [$this->magazine, $this->journal]);
        Sanctum::actingAs($journalEditor);
        $this->getJson('/api/admin/journals/'.$this->journal->slug)->assertOk();
        $this->getJson('/api/admin/magazines/'.$this->magazine->slug)->assertForbidden();
    }

    public function test_all_publication_editor_roles_have_read_only_public_page_access(): void
    {
        MagazinePage::create([
            'magazine_id' => $this->magazine->id,
            'title' => 'Magazine Public Policy',
            'slug' => 'magazine-public-policy',
            'content' => 'Magazine policy',
        ]);
        MagazinePage::create([
            'magazine_id' => $this->journal->id,
            'title' => 'Journal Public Policy',
            'slug' => 'journal-public-policy',
            'content' => 'Journal policy',
        ]);

        foreach ([
            'super_editor' => [$this->magazine, '/api/admin/magazines/'.$this->magazine->slug, 'Magazine Public Policy'],
            'magazine_editor' => [$this->magazine, '/api/admin/magazines/'.$this->magazine->slug, 'Magazine Public Policy'],
            'journal_editor' => [$this->journal, '/api/admin/journals/'.$this->journal->slug, 'Journal Public Policy'],
        ] as $roleName => [$publication, $detailEndpoint, $pageTitle]) {
            $editor = $this->editor($roleName, [$publication]);
            Sanctum::actingAs($editor);

            $this->assertTrue($editor->hasPermission('magazines.view-own'));
            $this->assertFalse($editor->hasPermission('magazines.edit'));
            $this->assertFalse($editor->hasPermission('magazines.pages.manage'));

            $this->getJson($detailEndpoint)
                ->assertOk()
                ->assertJsonFragment(['title' => $pageTitle]);

            $pageEndpoint = str_contains($detailEndpoint, '/journals/')
                ? "/api/admin/journals/{$publication->id}/pages"
                : "/api/admin/magazines/{$publication->id}/pages";
            $existingPage = MagazinePage::where('magazine_id', $publication->id)->firstOrFail();
            $this->postJson($pageEndpoint, [
                'title' => 'Editor Must Not Create',
                'content' => 'Forbidden',
            ])->assertForbidden();
            $this->putJson($pageEndpoint.'/'.$existingPage->id, [
                'title' => 'Editor Must Not Update',
                'content' => 'Forbidden',
            ])->assertForbidden();
            $this->deleteJson($pageEndpoint.'/'.$existingPage->id)->assertForbidden();

            $this->assertDatabaseHas('magazine_pages', [
                'id' => $existingPage->id,
                'title' => $pageTitle,
            ]);
        }
    }

    public function test_admin_cannot_create_update_or_delete_magazines_and_journals(): void
    {
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id')]);
        Sanctum::actingAs($admin);

        $this->assertFalse($admin->hasPermission('magazines.create'));
        $this->assertFalse($admin->hasPermission('magazines.edit'));
        $this->assertFalse($admin->hasPermission('magazines.delete'));

        $this->postJson('/api/admin/magazines', ['title' => 'Admin Magazine'])->assertForbidden();
        $this->putJson('/api/admin/magazines/'.$this->magazine->id, ['title' => 'Admin Updated Magazine'])->assertForbidden();
        $this->deleteJson('/api/admin/magazines/'.$this->magazine->id)->assertForbidden();

        $this->postJson('/api/admin/journals', ['title' => 'Admin Journal'])->assertForbidden();
        $this->putJson('/api/admin/journals/'.$this->journal->id, ['title' => 'Admin Updated Journal'])->assertForbidden();
        $this->deleteJson('/api/admin/journals/'.$this->journal->id)->assertForbidden();

        $this->assertDatabaseHas('magazines', ['id' => $this->magazine->id, 'title' => 'Scoped Magazine']);
        $this->assertDatabaseHas('magazines', ['id' => $this->journal->id, 'title' => 'Scoped Journal']);
    }

    public function test_admin_can_manage_public_pages_without_managing_publication_records(): void
    {
        $admin = User::factory()->create(['role_id' => Role::where('name', 'admin')->value('id')]);
        Sanctum::actingAs($admin);

        $this->assertTrue($admin->hasPermission('magazines.pages.manage'));

        $pageId = $this->postJson('/api/admin/magazines/'.$this->magazine->id.'/pages', [
            'title' => 'Admin Managed Page',
            'content' => 'Initial body',
        ])->assertStatus(211)->json('page.id');

        $this->putJson('/api/admin/magazines/'.$this->magazine->id.'/pages/'.$pageId, [
            'title' => 'Admin Managed Page Updated',
            'content' => 'Updated body',
        ])->assertOk();

        $this->deleteJson('/api/admin/magazines/'.$this->magazine->id.'/pages/'.$pageId)
            ->assertForbidden();
        $this->assertDatabaseHas('magazine_pages', [
            'id' => $pageId,
            'title' => 'Admin Managed Page Updated',
        ]);
    }

    public function test_super_admin_can_create_update_and_delete_magazines_and_journals(): void
    {
        $superAdmin = User::factory()->create(['role_id' => Role::where('name', 'super_admin')->value('id')]);
        Sanctum::actingAs($superAdmin);

        $magazineId = $this->postJson('/api/admin/magazines', ['title' => 'Super Magazine'])
            ->assertStatus(211)
            ->json('magazine.id');
        $this->putJson('/api/admin/magazines/'.$magazineId, ['title' => 'Super Magazine Updated'])
            ->assertOk();
        $this->deleteJson('/api/admin/magazines/'.$magazineId)->assertOk();

        $journalId = $this->postJson('/api/admin/journals', [
            'title' => 'Super Journal',
            'publication_type' => 'journal',
        ])->assertStatus(211)->json('magazine.id');
        $this->putJson('/api/admin/journals/'.$journalId, ['title' => 'Super Journal Updated'])
            ->assertOk();
        $this->deleteJson('/api/admin/journals/'.$journalId)->assertOk();

        $this->assertDatabaseMissing('magazines', ['id' => $magazineId]);
        $this->assertDatabaseMissing('magazines', ['id' => $journalId]);
    }

    private function editor(string $role, array $publications): User
    {
        $user = User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
        foreach ($publications as $publication) {
            $user->magazines()->attach($publication->id, ['role' => 'editor']);
        }
        return $user;
    }

    private function article(User $author, Magazine $publication, string $title): Article
    {
        return Article::create([
            'magazine_id' => $publication->id,
            'user_id' => $author->id,
            'title' => $title,
            'slug' => str($title)->slug(),
            'abstract' => 'Abstract',
            'full_text' => '',
            'status' => ArticleStatus::SCREENING,
        ]);
    }
}
