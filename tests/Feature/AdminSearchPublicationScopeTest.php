<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Magazine;
use App\Models\MagazineIssue;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\SystemPermissionSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminSearchPublicationScopeTest extends TestCase
{
    use RefreshDatabase;

    private Magazine $magazine;
    private Magazine $journal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemRoleSeeder::class);
        $this->seed(SystemPermissionSeeder::class);

        $author = User::factory()->create(['role_id' => Role::where('name', 'author')->value('id')]);
        $this->magazine = Magazine::create([
            'title' => 'Magazine Scope Publication',
            'slug' => 'magazine-scope-publication',
            'publication_type' => Magazine::TYPE_MAGAZINE,
        ]);
        $this->journal = Magazine::create([
            'title' => 'Journal Scope Publication',
            'slug' => 'journal-scope-publication',
            'publication_type' => Magazine::TYPE_JOURNAL,
        ]);

        $this->article($author, $this->magazine, 'Magazine Scope Article');
        $this->article($author, $this->journal, 'Journal Scope Article');
        MagazineIssue::create(['magazine_id' => $this->magazine->id, 'volume_number' => 1, 'issue_number' => 1, 'special_title' => 'Magazine Scope Issue']);
        MagazineIssue::create(['magazine_id' => $this->journal->id, 'volume_number' => 1, 'issue_number' => 1, 'special_title' => 'Journal Scope Issue']);
    }

    public function test_magazine_editor_search_excludes_stale_journal_article_assignment(): void
    {
        $response = $this->searchAs($this->editorWithStaleCrossTypeAssignment('magazine_editor'));

        $this->assertSame(['Magazine Scope Article'], $this->titles($response, 'articles'));
    }

    public function test_journal_editor_search_excludes_stale_magazine_article_assignment(): void
    {
        $response = $this->searchAs($this->editorWithStaleCrossTypeAssignment('journal_editor'));

        $this->assertSame(['Journal Scope Article'], $this->titles($response, 'articles'));
    }

    public function test_publication_editor_search_requires_assignment_even_for_an_allowed_type(): void
    {
        $author = User::factory()->create(['role_id' => Role::where('name', 'author')->value('id')]);
        $unassignedMagazine = Magazine::create([
            'title' => 'Unassigned Magazine Scope Publication',
            'slug' => 'unassigned-magazine-scope-publication',
            'publication_type' => Magazine::TYPE_MAGAZINE,
        ]);
        $this->article($author, $unassignedMagazine, 'Unassigned Magazine Scope Article');

        $response = $this->searchAs($this->editorWithStaleCrossTypeAssignment('magazine_editor'));

        $this->assertSame(['Magazine Scope Article'], $this->titles($response, 'articles'));
    }

    public function test_magazine_editor_search_excludes_stale_journal_publication_assignment(): void
    {
        $response = $this->searchAs($this->editorWithStaleCrossTypeAssignment('magazine_editor'));

        $this->assertSame(['Magazine Scope Publication'], $this->titles($response, 'magazines'));
    }

    public function test_journal_editor_search_excludes_stale_magazine_publication_assignment(): void
    {
        $response = $this->searchAs($this->editorWithStaleCrossTypeAssignment('journal_editor'));

        $this->assertSame(['Journal Scope Publication'], $this->titles($response, 'magazines'));
    }

    public function test_magazine_editor_search_excludes_stale_journal_issue_assignment(): void
    {
        $response = $this->searchAs($this->editorWithStaleCrossTypeAssignment('magazine_editor'));

        $this->assertSame(['Magazine Scope Issue'], $this->titles($response, 'issues', 'special_title'));
    }

    public function test_journal_editor_search_excludes_stale_magazine_issue_assignment(): void
    {
        $response = $this->searchAs($this->editorWithStaleCrossTypeAssignment('journal_editor'));

        $this->assertSame(['Journal Scope Issue'], $this->titles($response, 'issues', 'special_title'));
    }

    public function test_super_editor_search_returns_assigned_magazine_and_journal_results(): void
    {
        $response = $this->searchAs($this->editorWithStaleCrossTypeAssignment('super_editor'));

        $this->assertSame(['Journal Scope Article', 'Magazine Scope Article'], $this->titles($response, 'articles'));
        $this->assertSame(['Journal Scope Publication', 'Magazine Scope Publication'], $this->titles($response, 'magazines'));
        $this->assertSame(['Journal Scope Issue', 'Magazine Scope Issue'], $this->titles($response, 'issues', 'special_title'));
    }

    public function test_admin_and_super_admin_search_remains_global(): void
    {
        foreach (['admin', 'super_admin'] as $roleName) {
            $response = $this->searchAs(User::factory()->create(['role_id' => Role::where('name', $roleName)->value('id')]));

            $this->assertSame(['Journal Scope Article', 'Magazine Scope Article'], $this->titles($response, 'articles'));
            $this->assertSame(['Journal Scope Publication', 'Magazine Scope Publication'], $this->titles($response, 'magazines'));
            $this->assertSame(['Journal Scope Issue', 'Magazine Scope Issue'], $this->titles($response, 'issues', 'special_title'));
        }
    }

    private function editorWithStaleCrossTypeAssignment(string $roleName): User
    {
        $editor = User::factory()->create(['role_id' => Role::where('name', $roleName)->value('id')]);
        $editor->magazines()->attach($this->magazine->id, ['role' => 'editor']);
        $editor->magazines()->attach($this->journal->id, ['role' => 'editor']);

        return $editor;
    }

    private function searchAs(User $user)
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/admin/search?q=Scope')->assertOk();
    }

    private function titles($response, string $group, string $field = 'title'): array
    {
        return collect($response->json($group))->pluck($field)->sort()->values()->all();
    }

    private function article(User $author, Magazine $publication, string $title): Article
    {
        return Article::create([
            'magazine_id' => $publication->id,
            'user_id' => $author->id,
            'title' => $title,
            'slug' => str($title)->slug(),
            'abstract' => $title.' abstract',
            'full_text' => '',
            'status' => 'submitted',
        ]);
    }
}
