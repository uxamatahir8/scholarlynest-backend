<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\Magazine;
use App\Models\MagazineIssue;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublisherIssueWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;
    private User $otherPublisher;
    private User $author;
    private Magazine $magazine;
    private Magazine $otherMagazine;

    protected function setUp(): void
    {
        parent::setUp();

        $publisherRole = Role::create(['name' => 'publisher', 'display_name' => 'Publisher', 'is_system' => true]);
        $authorRole = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);

        foreach (['articles.view-own', 'articles.approve'] as $permission) {
            Permission::firstOrCreate(['name' => $permission], ['module' => 'articles', 'description' => $permission]);
        }
        $publisherRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.approve'])->pluck('id'));

        $this->publisher = User::factory()->create(['role_id' => $publisherRole->id]);
        $this->otherPublisher = User::factory()->create(['role_id' => $publisherRole->id]);
        $this->author = User::factory()->create(['role_id' => $authorRole->id]);

        $this->magazine = Magazine::create([
            'title' => 'Publisher Journal',
            'slug' => 'publisher-journal',
            'description' => 'Publisher test journal',
        ]);
        $this->otherMagazine = Magazine::create([
            'title' => 'Other Journal',
            'slug' => 'other-journal',
            'description' => 'Other test journal',
        ]);

        $this->publisher->magazines()->attach($this->magazine->id, ['role' => 'publisher']);
        $this->otherPublisher->magazines()->attach($this->otherMagazine->id, ['role' => 'publisher']);
    }

    public function test_publisher_can_manage_only_assigned_magazine_issues(): void
    {
        Sanctum::actingAs($this->publisher);

        $issueId = $this->postJson('/api/admin/issues', [
            'magazine_id' => $this->magazine->id,
            'volume_number' => 1,
            'issue_number' => 1,
            'issue_month' => 'June',
            'issue_year' => 2026,
            'special_title' => 'Launch Issue',
        ])->assertCreated()
            ->assertJsonPath('issue.special_title', 'Launch Issue')
            ->json('issue.id');

        $this->postJson("/api/admin/issues/{$issueId}", [
            'magazine_id' => $this->magazine->id,
            'volume_number' => 1,
            'issue_number' => 2,
            'status' => 'draft',
        ])->assertOk()
            ->assertJsonPath('issue.issue_number', 2);

        $this->postJson('/api/admin/issues', [
            'magazine_id' => $this->otherMagazine->id,
            'volume_number' => 1,
            'issue_number' => 1,
        ])->assertForbidden();
    }

    public function test_publish_article_requires_eligible_status_and_matching_issue_magazine(): void
    {
        Sanctum::actingAs($this->publisher);

        $issue = MagazineIssue::create([
            'magazine_id' => $this->magazine->id,
            'volume_number' => 2,
            'issue_number' => 1,
            'status' => 'draft',
        ]);
        $otherIssue = MagazineIssue::create([
            'magazine_id' => $this->otherMagazine->id,
            'volume_number' => 1,
            'issue_number' => 1,
            'status' => 'draft',
        ]);

        $draft = $this->article(ArticleStatus::DRAFT);
        $this->postJson("/api/admin/articles/{$draft->id}/publish", [
            'magazine_issue_id' => $issue->id,
            'published_year' => 2026,
            'published_month' => 'June',
        ])->assertStatus(422);

        $accepted = $this->article(ArticleStatus::ACCEPTED);
        $this->postJson("/api/admin/articles/{$accepted->id}/publish", [
            'magazine_issue_id' => $otherIssue->id,
            'published_year' => 2026,
            'published_month' => 'June',
        ])->assertStatus(422);

        $this->postJson("/api/admin/articles/{$accepted->id}/publish", [
            'magazine_issue_id' => $issue->id,
            'doi' => '10.1234/pub.1',
            'published_year' => 2026,
            'published_month' => 'June',
            'page_start' => 1,
            'page_end' => 12,
        ])->assertOk()
            ->assertJsonPath('article.status', ArticleStatus::PUBLISHED)
            ->assertJsonPath('article.magazine_issue_id', $issue->id)
            ->assertJsonPath('citation.format', 'APA')
            ->assertJsonPath('citation.text', fn ($citation) => str_contains($citation, '10.1234/pub.1'));
    }

    public function test_eligible_articles_are_scoped_to_assigned_magazine(): void
    {
        $ready = $this->article(ArticleStatus::READY_FOR_PUBLICATION);
        $this->article(ArticleStatus::UNDER_REVIEW);
        Article::create([
            'magazine_id' => $this->otherMagazine->id,
            'user_id' => $this->author->id,
            'title' => 'Other Ready',
            'slug' => 'other-ready',
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => ArticleStatus::READY_FOR_PUBLICATION,
        ]);

        Sanctum::actingAs($this->publisher);

        $this->getJson('/api/admin/issues/eligible-articles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ready->id);
    }

    private function article(string $status): Article
    {
        return Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->author->id,
            'title' => 'Article ' . $status,
            'slug' => 'article-' . str_replace('_', '-', $status) . '-' . uniqid(),
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => $status,
        ]);
    }
}
