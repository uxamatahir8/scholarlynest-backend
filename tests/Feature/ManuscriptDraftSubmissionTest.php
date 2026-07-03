<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Events\ArticleSubmitted;
use App\Models\Article;
use App\Models\Magazine;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManuscriptDraftSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private Role $authorRole;
    private Role $superAdminRole;
    private Magazine $magazine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authorRole = Role::create([
            'name' => 'author',
            'display_name' => 'Author',
            'is_system' => true,
        ]);
        $this->superAdminRole = Role::create([
            'name' => 'super_admin',
            'display_name' => 'Super Admin',
            'is_system' => true,
        ]);

        foreach (['articles.view-own', 'articles.create', 'articles.edit-own', 'articles.manage-assets'] as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName], [
                'module' => 'articles',
                'description' => $permissionName,
            ]);
        }

        $this->authorRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.create', 'articles.edit-own', 'articles.manage-assets'])->pluck('id'));
        $this->superAdminRole->permissions()->sync(Permission::pluck('id'));

        $this->magazine = Magazine::create([
            'title' => 'Draft Workflow Journal',
            'slug' => 'draft-workflow-journal',
            'description' => 'Journal used for manuscript draft tests.',
        ]);
    }

    public function test_author_can_create_a_draft_without_submission_event(): void
    {
        Event::fake([ArticleSubmitted::class]);
        $author = $this->author();
        Sanctum::actingAs($author);

        $response = $this->postJson('/api/articles', array_merge($this->articlePayload($author), [
            'status' => ArticleStatus::DRAFT,
            'title' => 'Draft Manuscript',
        ]));

        $response->assertCreated()
            ->assertJsonPath('article.status', ArticleStatus::DRAFT);

        $this->assertDatabaseHas('articles', [
            'title' => 'Draft Manuscript',
            'user_id' => $author->id,
            'status' => ArticleStatus::DRAFT,
        ]);
        $this->assertDatabaseCount('article_versions', 0);
        Event::assertNotDispatched(ArticleSubmitted::class);
    }

    public function test_author_can_submit_their_saved_draft(): void
    {
        Event::fake([ArticleSubmitted::class]);
        $author = $this->author();
        $article = $this->article($author, ArticleStatus::DRAFT);
        Sanctum::actingAs($author);

        $response = $this->putJson("/api/admin/articles/{$article->id}", array_merge($this->articlePayload($author), [
            'title' => 'Submitted From Draft',
            'status' => ArticleStatus::SUBMITTED,
        ]));

        $response->assertOk()
            ->assertJsonPath('article.status', ArticleStatus::SUBMITTED);

        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'title' => 'Submitted From Draft',
            'status' => ArticleStatus::SUBMITTED,
        ]);
        $this->assertDatabaseHas('article_versions', [
            'article_id' => $article->id,
            'label' => 'Initial Submission',
            'status_snapshot' => ArticleStatus::SUBMITTED,
        ]);
    }

    public function test_author_cannot_edit_after_submission(): void
    {
        $author = $this->author();
        $article = $this->article($author, ArticleStatus::SUBMITTED);
        Sanctum::actingAs($author);

        $this->putJson("/api/admin/articles/{$article->id}", array_merge($this->articlePayload($author), [
            'title' => 'Locked Update',
            'status' => ArticleStatus::SUBMITTED,
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'title' => $article->title,
            'status' => ArticleStatus::SUBMITTED,
        ]);
    }

    public function test_super_admin_must_assign_manuscript_owner_to_an_author(): void
    {
        $superAdmin = $this->superAdmin();
        Sanctum::actingAs($superAdmin);

        $this->postJson('/api/articles', array_merge($this->articlePayload($superAdmin), [
            'status' => ArticleStatus::DRAFT,
        ]))->assertStatus(422);

        $assignedAuthor = $this->author(['email' => 'assigned.author@example.test']);
        $response = $this->postJson('/api/articles', array_merge($this->articlePayload($assignedAuthor), [
            'status' => ArticleStatus::DRAFT,
            'title' => 'Super Admin Assigned Draft',
        ]));

        $response->assertCreated()
            ->assertJsonPath('article.status', ArticleStatus::DRAFT);

        $articleId = $response->json('article.id');
        $this->assertDatabaseHas('articles', [
            'id' => $articleId,
            'user_id' => $assignedAuthor->id,
            'status' => ArticleStatus::DRAFT,
        ]);
        $this->assertDatabaseMissing('articles', [
            'id' => $articleId,
            'user_id' => $superAdmin->id,
        ]);
    }

    private function author(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => $this->authorRole->id,
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'role_id' => $this->superAdminRole->id,
            'email_verified_at' => now(),
        ]);
    }

    private function article(User $author, string $status): Article
    {
        return Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $author->id,
            'title' => 'Draft Test Article ' . Str::random(8),
            'slug' => 'draft-test-article-' . Str::random(8),
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => $status,
        ]);
    }

    private function articlePayload(User $owner): array
    {
        return [
            'magazine_id' => $this->magazine->id,
            'title' => 'Draft Workflow Manuscript',
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'authors' => [[
                'name' => $owner->name,
                'email' => $owner->email,
                'affiliation' => 'University',
                'author_order' => 1,
                'is_owner' => true,
                'is_corresponding' => true,
                'can_edit' => true,
                'create_account' => false,
            ]],
        ];
    }
}
