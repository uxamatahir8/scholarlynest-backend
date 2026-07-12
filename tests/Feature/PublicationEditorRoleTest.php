<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\Magazine;
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
