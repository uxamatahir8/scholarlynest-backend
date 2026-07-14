<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\Magazine;
use App\Models\MagazineIssue;
use App\Models\Permission;
use App\Models\ProductionAssignment;
use App\Models\ReviewerAssignment;
use App\Models\Role;
use App\Models\SubEditorAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeskObserverTest extends TestCase
{
    use RefreshDatabase;

    private array $roles = [];
    private User $superAdmin;
    private User $reviewerA;
    private User $reviewerB;
    private User $subEditorA;
    private User $subEditorB;
    private User $copyEditorA;
    private User $copyEditorB;
    private User $proofreader;
    private User $publisher;
    private User $editor;
    private User $superEditor;
    private User $magazineEditor;
    private User $journalEditor;
    private User $author;
    private Magazine $magazineA;
    private Magazine $magazineB;
    private Magazine $journal;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'admin', 'author', 'reviewer', 'sub_editor', 'copy_editor', 'proofreader', 'publisher', 'editor', 'super_editor', 'magazine_editor', 'journal_editor'] as $roleName) {
            $this->roles[$roleName] = Role::create([
                'name' => $roleName,
                'display_name' => Str::headline($roleName),
                'is_system' => true,
            ]);
        }

        foreach (['articles.view-own', 'articles.approve'] as $permission) {
            Permission::firstOrCreate(['name' => $permission], ['module' => 'articles', 'description' => $permission]);
        }

        foreach (['reviewer', 'sub_editor', 'copy_editor', 'proofreader', 'publisher', 'editor', 'super_editor', 'magazine_editor', 'journal_editor'] as $roleName) {
            $this->roles[$roleName]->permissions()->sync(Permission::where('name', 'articles.view-own')->pluck('id'));
        }
        $this->roles['editor']->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.approve'])->pluck('id'));
        $this->roles['publisher']->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.approve'])->pluck('id'));

        $this->superAdmin = $this->user('super_admin', 'Super Admin');
        $this->author = $this->user('author', 'Author');
        $this->reviewerA = $this->user('reviewer', 'Reviewer A');
        $this->reviewerB = $this->user('reviewer', 'Reviewer B');
        $this->subEditorA = $this->user('sub_editor', 'Sub Editor A');
        $this->subEditorB = $this->user('sub_editor', 'Sub Editor B');
        $this->copyEditorA = $this->user('copy_editor', 'Copy Editor A');
        $this->copyEditorB = $this->user('copy_editor', 'Copy Editor B');
        $this->proofreader = $this->user('proofreader', 'Proofreader A');
        $this->publisher = $this->user('publisher', 'Publisher A');
        $this->editor = $this->user('editor', 'Editor A');
        $this->superEditor = $this->user('super_editor', 'Super Editor A');
        $this->magazineEditor = $this->user('magazine_editor', 'Magazine Editor A');
        $this->journalEditor = $this->user('journal_editor', 'Journal Editor A');

        $this->magazineA = $this->magazine('Magazine A');
        $this->magazineB = $this->magazine('Magazine B');
        $this->journal = $this->magazine('Journal A', Magazine::TYPE_JOURNAL);

        $this->proofreader->magazines()->attach($this->magazineA->id, ['role' => 'proofreader']);
        $this->publisher->magazines()->attach($this->magazineA->id, ['role' => 'publisher']);
        $this->editor->magazines()->attach($this->magazineA->id, ['role' => 'editor']);
        $this->superEditor->magazines()->attach($this->magazineA->id, ['role' => 'editor']);
        $this->superEditor->magazines()->attach($this->journal->id, ['role' => 'editor']);
        $this->magazineEditor->magazines()->attach($this->magazineA->id, ['role' => 'editor']);
        $this->magazineEditor->magazines()->attach($this->journal->id, ['role' => 'editor']);
        $this->journalEditor->magazines()->attach($this->magazineA->id, ['role' => 'editor']);
        $this->journalEditor->magazines()->attach($this->journal->id, ['role' => 'editor']);
    }

    public function test_selector_is_super_admin_only_and_minimized(): void
    {
        Sanctum::actingAs($this->reviewerA);
        $this->getJson('/api/admin/desk-observer/users?role=reviewer')->assertForbidden();

        Sanctum::actingAs($this->superAdmin);
        $payload = $this->getJson('/api/admin/desk-observer/users?role=reviewer')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Reviewer A', 'role' => 'reviewer'])
            ->assertJsonMissing(['email' => $this->reviewerA->email])
            ->json();

        $this->assertArrayNotHasKey('email', $payload['users'][0]);
        $this->assertArrayNotHasKey('permissions', $payload['users'][0]);
        $this->assertArrayNotHasKey('token', $payload['users'][0]);
    }

    public function test_editor_selector_includes_current_editor_roles(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $response = $this->getJson('/api/admin/desk-observer/users?role=editor')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Super Editor A', 'role' => 'super_editor'])
            ->assertJsonFragment(['name' => 'Magazine Editor A', 'role' => 'magazine_editor'])
            ->assertJsonFragment(['name' => 'Journal Editor A', 'role' => 'journal_editor']);

        $this->assertSame(4, count($response->json('users')));
    }

    public function test_current_editor_observer_desks_keep_assignment_and_publication_type_scope(): void
    {
        $magazineArticle = $this->article($this->magazineA, 'Current Magazine Editor Article', ArticleStatus::UNDER_REVIEW);
        $journalArticle = $this->article($this->journal, 'Current Journal Editor Article', ArticleStatus::UNDER_REVIEW);

        Sanctum::actingAs($this->superAdmin);

        $this->getJson("/api/admin/articles?observer_user_id={$this->magazineEditor->id}")
            ->assertOk()
            ->assertJsonFragment(['title' => $magazineArticle->title])
            ->assertJsonMissing(['title' => $journalArticle->title]);

        $this->getJson("/api/admin/articles?observer_user_id={$this->journalEditor->id}")
            ->assertOk()
            ->assertJsonFragment(['title' => $journalArticle->title])
            ->assertJsonMissing(['title' => $magazineArticle->title]);

        $this->getJson("/api/admin/articles?observer_user_id={$this->superEditor->id}")
            ->assertOk()
            ->assertJsonFragment(['title' => $magazineArticle->title])
            ->assertJsonFragment(['title' => $journalArticle->title]);
    }

    public function test_impersonated_super_admin_cannot_use_selector_or_observer_endpoints(): void
    {
        $token = $this->superAdmin->createToken('impersonation_token')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/desk-observer/users?role=reviewer')
            ->assertUnauthorized();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/my-reviewer-assignments?observer_user_id={$this->reviewerA->id}")
            ->assertUnauthorized();
    }

    public function test_non_super_admin_cannot_use_observer_user_id(): void
    {
        Sanctum::actingAs($this->reviewerA);

        $this->getJson("/api/admin/my-reviewer-assignments?observer_user_id={$this->reviewerB->id}")
            ->assertForbidden();
    }

    public function test_invalid_or_wrong_role_observer_user_is_rejected(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->getJson('/api/admin/my-reviewer-assignments?observer_user_id=999999')
            ->assertStatus(422);

        $this->getJson("/api/admin/my-reviewer-assignments?observer_user_id={$this->subEditorA->id}")
            ->assertStatus(422);
    }

    public function test_reviewer_and_sub_editor_observer_results_are_selected_user_scoped(): void
    {
        $articleA = $this->article($this->magazineA, 'Reviewer A Article', ArticleStatus::REVIEWER_ASSIGNED);
        $articleB = $this->article($this->magazineA, 'Reviewer B Article', ArticleStatus::REVIEWER_ASSIGNED);
        ReviewerAssignment::create(['article_id' => $articleA->id, 'reviewer_id' => $this->reviewerA->id, 'assigned_by' => $this->superAdmin->id, 'status' => 'pending']);
        ReviewerAssignment::create(['article_id' => $articleB->id, 'reviewer_id' => $this->reviewerB->id, 'assigned_by' => $this->superAdmin->id, 'status' => 'pending']);

        $subArticleA = $this->article($this->magazineA, 'Sub Editor A Article', ArticleStatus::ASSIGNED_TO_SUB_EDITOR);
        $subArticleB = $this->article($this->magazineA, 'Sub Editor B Article', ArticleStatus::ASSIGNED_TO_SUB_EDITOR);
        SubEditorAssignment::create(['article_id' => $subArticleA->id, 'sub_editor_id' => $this->subEditorA->id, 'assigned_by' => $this->superAdmin->id, 'status' => 'pending']);
        SubEditorAssignment::create(['article_id' => $subArticleB->id, 'sub_editor_id' => $this->subEditorB->id, 'assigned_by' => $this->superAdmin->id, 'status' => 'pending']);

        Sanctum::actingAs($this->superAdmin);

        $this->getJson("/api/admin/my-reviewer-assignments?observer_user_id={$this->reviewerA->id}")
            ->assertOk()
            ->assertJsonFragment(['title' => $articleA->title])
            ->assertJsonMissing(['title' => $articleB->title])
            ->assertJsonMissing(['name' => $this->reviewerB->name]);

        $this->getJson("/api/admin/my-sub-editor-assignments?observer_user_id={$this->subEditorA->id}")
            ->assertOk()
            ->assertJsonFragment(['title' => $subArticleA->title])
            ->assertJsonMissing(['title' => $subArticleB->title]);
    }

    public function test_production_observer_results_are_selected_user_and_role_scoped(): void
    {
        $copyArticleA = $this->article($this->magazineA, 'Copy Editor A Article', ArticleStatus::COPY_EDITING);
        $copyArticleB = $this->article($this->magazineA, 'Copy Editor B Article', ArticleStatus::COPY_EDITING);
        ProductionAssignment::create(['article_id' => $copyArticleA->id, 'user_id' => $this->copyEditorA->id, 'assigned_by' => $this->superAdmin->id, 'role' => 'copy_editor', 'status' => 'pending']);
        ProductionAssignment::create(['article_id' => $copyArticleB->id, 'user_id' => $this->copyEditorB->id, 'assigned_by' => $this->superAdmin->id, 'role' => 'copy_editor', 'status' => 'pending']);

        $proofAllowed = $this->article($this->magazineA, 'Proofreader Allowed Article', ArticleStatus::PROOFREADING);
        $proofBlocked = $this->article($this->magazineB, 'Proofreader Blocked Article', ArticleStatus::PROOFREADING);
        ProductionAssignment::create(['article_id' => $proofAllowed->id, 'user_id' => $this->proofreader->id, 'assigned_by' => $this->superAdmin->id, 'role' => 'proofreader', 'status' => 'pending']);
        ProductionAssignment::create(['article_id' => $proofBlocked->id, 'user_id' => $this->proofreader->id, 'assigned_by' => $this->superAdmin->id, 'role' => 'proofreader', 'status' => 'pending']);

        Sanctum::actingAs($this->superAdmin);

        $this->getJson("/api/admin/my-production-assignments?role=copy_editor&observer_user_id={$this->copyEditorA->id}")
            ->assertOk()
            ->assertJsonFragment(['title' => $copyArticleA->title])
            ->assertJsonMissing(['title' => $copyArticleB->title]);

        $this->getJson("/api/admin/my-production-assignments?role=proofreader&observer_user_id={$this->proofreader->id}")
            ->assertStatus(422);
    }

    public function test_publisher_and_editor_observer_results_are_magazine_scoped(): void
    {
        MagazineIssue::create(['magazine_id' => $this->magazineA->id, 'volume_number' => 1, 'issue_number' => 1, 'status' => 'draft']);
        MagazineIssue::create(['magazine_id' => $this->magazineB->id, 'volume_number' => 1, 'issue_number' => 1, 'status' => 'draft']);
        $publisherArticle = $this->article($this->magazineA, 'Publisher Ready Article', ArticleStatus::READY_FOR_PUBLICATION);
        $blockedPublisherArticle = $this->article($this->magazineB, 'Publisher Blocked Article', ArticleStatus::READY_FOR_PUBLICATION);
        $editorArticle = $this->article($this->magazineA, 'Editor Scoped Article', ArticleStatus::UNDER_REVIEW);
        $blockedEditorArticle = $this->article($this->magazineB, 'Editor Blocked Article', ArticleStatus::UNDER_REVIEW);

        Sanctum::actingAs($this->superAdmin);

        $this->getJson("/api/admin/publisher-dashboard?observer_user_id={$this->publisher->id}")
            ->assertOk()
            ->assertJsonFragment(['title' => $publisherArticle->title])
            ->assertJsonMissing(['title' => $blockedPublisherArticle->title])
            ->assertJsonPath('counts.magazines', 1);

        $this->getJson("/api/admin/articles?observer_user_id={$this->editor->id}")
            ->assertOk()
            ->assertJsonFragment(['title' => $editorArticle->title])
            ->assertJsonMissing(['title' => $blockedEditorArticle->title]);
    }

    public function test_observer_identity_is_rejected_on_workflow_mutations(): void
    {
        $article = $this->article($this->magazineA, 'Read Only Review', ArticleStatus::REVIEWER_ASSIGNED);
        $assignment = ReviewerAssignment::create([
            'article_id' => $article->id,
            'reviewer_id' => $this->reviewerA->id,
            'assigned_by' => $this->superAdmin->id,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($this->superAdmin);

        $this->postJson("/api/admin/reviewer-assignments/{$assignment->id}/accept", [
            'observer_user_id' => $this->reviewerA->id,
        ])->assertStatus(422);

        $this->assertDatabaseHas('reviewer_assignments', [
            'id' => $assignment->id,
            'status' => 'pending',
        ]);
    }

    private function user(string $roleName, string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'role_id' => $this->roles[$roleName]->id,
            'email_verified_at' => now(),
        ]);
    }

    private function magazine(string $title, string $publicationType = Magazine::TYPE_MAGAZINE): Magazine
    {
        return Magazine::create([
            'title' => $title,
            'slug' => Str::slug($title) . '-' . uniqid(),
            'description' => 'Observer test magazine',
            'publication_type' => $publicationType,
        ]);
    }

    private function article(Magazine $magazine, string $title, string $status): Article
    {
        return Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $this->author->id,
            'title' => $title,
            'slug' => Str::slug($title) . '-' . uniqid(),
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => $status,
        ]);
    }
}
