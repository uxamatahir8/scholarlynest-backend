<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleFile;
use App\Models\ArticleVersion;
use App\Models\Magazine;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArticleStatusNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private Magazine $magazine;
    private User $author;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create(['name' => 'super_admin', 'display_name' => 'Super Admin', 'is_system' => true]);
        $authorRole = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);

        foreach (['articles.create', 'articles.view-own', 'articles.edit-own', 'articles.approve'] as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission],
                ['module' => 'articles', 'description' => $permission]
            );
        }

        $authorRole->permissions()->sync(Permission::whereIn('name', ['articles.create', 'articles.view-own', 'articles.edit-own'])->pluck('id'));

        $this->admin = User::factory()->create(['role_id' => $adminRole->id]);
        $this->author = User::factory()->create(['role_id' => $authorRole->id]);
        $this->magazine = Magazine::create([
            'title' => 'Status Magazine',
            'slug' => 'status-magazine',
            'description' => 'Status test magazine',
        ]);
    }

    public function test_article_submission_saves_submitted_status(): void
    {
        Sanctum::actingAs($this->author);

        $this->postJson('/api/articles', [
            'magazine_id' => $this->magazine->id,
            'title' => 'Normalized Submission',
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'terms_accepted' => true,
            'pdf_upload_id' => $this->cleanManuscriptUpload($this->author)->id,
        ])->assertStatus(211)
            ->assertJsonPath('article.status', ArticleStatus::SUBMITTED);
    }

    public function test_legacy_review_statuses_are_saved_as_normalized_statuses(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin);

        $acceptedArticle = $this->articleWithStatus('legacy-accepted', 'pending');
        $acceptedVersion = ArticleVersion::create([
            'article_id' => $acceptedArticle->id,
            'created_by' => $this->author->id,
            'version_number' => 1,
            'label' => 'Initial Submission',
            'status_snapshot' => ArticleStatus::SUBMITTED,
        ]);
        ArticleFile::create([
            'article_id' => $acceptedArticle->id,
            'article_version_id' => $acceptedVersion->id,
            'uploaded_by' => $this->author->id,
            'file_type' => ArticleFile::MANUSCRIPT,
            'visibility' => 'author_visible',
            'file_path' => 'clean/status-manuscript.pdf',
            'original_name' => 'status-manuscript.pdf',
            'mime_type' => 'application/pdf',
            'size' => 14,
            'scan_status' => 'clean',
        ]);
        $this->patchJson("/api/admin/articles/{$acceptedArticle->id}/review", [
            'status' => 'approved',
        ])->assertStatus(200)
            ->assertJsonPath('article.status', ArticleStatus::ACCEPTED);

        $revisionArticle = $this->articleWithStatus('legacy-revision', 'pending');
        $this->patchJson("/api/admin/articles/{$revisionArticle->id}/review", [
            'status' => 'minor_review_rejected',
            'rejection_reason' => 'Please revise the methods section.',
        ])->assertStatus(200)
            ->assertJsonPath('article.status', ArticleStatus::REVISION_REQUIRED);

        $rejectedArticle = $this->articleWithStatus('legacy-rejected', 'pending');
        $this->patchJson("/api/admin/articles/{$rejectedArticle->id}/review", [
            'status' => 'fully_rejected',
            'rejection_reason' => 'Out of scope.',
        ])->assertStatus(200)
            ->assertJsonPath('article.status', ArticleStatus::REJECTED);
    }

    public function test_invalid_status_transition_is_blocked(): void
    {
        Sanctum::actingAs($this->admin);

        $article = $this->articleWithStatus('rejected-transition', ArticleStatus::REJECTED);

        $this->patchJson("/api/admin/articles/{$article->id}/review", [
            'status' => 'approved',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_authors_cannot_edit_locked_statuses(): void
    {
        Sanctum::actingAs($this->author);

        foreach ([
            ArticleStatus::SUBMITTED,
            ArticleStatus::UNDER_REVIEW,
            ArticleStatus::ASSIGNED_TO_SUB_EDITOR,
            ArticleStatus::REVIEWER_ASSIGNED,
            ArticleStatus::REVIEW_IN_PROGRESS,
            ArticleStatus::ACCEPTED,
            ArticleStatus::COPY_EDITING,
            ArticleStatus::PROOFREADING,
            ArticleStatus::READY_FOR_PUBLICATION,
            ArticleStatus::PUBLISHED,
            ArticleStatus::REJECTED,
            ArticleStatus::WITHDRAWN,
            ArticleStatus::ARCHIVED,
        ] as $status) {
            $article = $this->articleWithStatus("locked-{$status}", $status);

            $this->putJson("/api/admin/articles/{$article->id}", $this->updatePayload($article, "Updated {$status}"))
                ->assertStatus(422)
                ->assertJsonPath('message', 'This manuscript cannot be edited at its current workflow stage.');
        }
    }

    public function test_authors_can_edit_drafts_and_revision_required_statuses(): void
    {
        Sanctum::actingAs($this->author);

        $draft = $this->articleWithStatus('editable-draft', ArticleStatus::DRAFT);
        $this->putJson("/api/admin/articles/{$draft->id}", $this->updatePayload($draft, 'Updated Draft'))
            ->assertStatus(200)
            ->assertJsonPath('article.status', ArticleStatus::DRAFT);

        foreach ([
            ArticleStatus::REVISION_REQUIRED,
            ArticleStatus::MINOR_REVISION_REQUIRED,
            ArticleStatus::MAJOR_REVISION_REQUIRED,
        ] as $status) {
            $article = $this->articleWithStatus("editable-{$status}", $status);

            $this->putJson("/api/admin/articles/{$article->id}", $this->updatePayload($article, "Updated {$status}"))
                ->assertStatus(200)
                ->assertJsonPath('article.status', ArticleStatus::RESUBMITTED);
        }

        foreach ([ArticleStatus::RESUBMITTED] as $status) {
            $article = $this->articleWithStatus("editable-{$status}", $status);

            $this->putJson("/api/admin/articles/{$article->id}", $this->updatePayload($article, "Updated {$status}"))
                ->assertStatus(200)
                ->assertJsonPath('article.status', $status);
        }
    }

    public function test_super_admin_cannot_bypass_non_editable_status_gate(): void
    {
        Sanctum::actingAs($this->admin);

        foreach ([ArticleStatus::SUBMITTED, ArticleStatus::ACCEPTED, ArticleStatus::READY_FOR_PUBLICATION, ArticleStatus::PUBLISHED] as $status) {
            $article = $this->articleWithStatus("admin-locked-{$status}", $status);

            $this->putJson("/api/admin/articles/{$article->id}", $this->updatePayload($article, "Admin Updated {$status}"))
                ->assertStatus(422)
                ->assertJsonPath('message', 'This manuscript cannot be edited at its current workflow stage.');
        }

    }

    public function test_edit_context_fetch_is_denied_for_non_editable_statuses_and_observer_mode(): void
    {
        Sanctum::actingAs($this->admin);

        $submitted = $this->articleWithStatus('edit-context-submitted', ArticleStatus::SUBMITTED);
        $this->getJson("/api/admin/articles/{$submitted->id}?view_context=edit")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This manuscript cannot be edited at its current workflow stage.');

        $ready = $this->articleWithStatus('edit-context-ready', ArticleStatus::READY_FOR_PUBLICATION);
        $this->getJson("/api/admin/articles/{$ready->id}?view_context=edit")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This manuscript cannot be edited at its current workflow stage.');

        $this->getJson("/api/admin/articles/{$ready->id}?view_context=edit&observer_readonly=1")
            ->assertForbidden()
            ->assertJsonPath('message', 'Observer mode is read-only.');
    }

    private function articleWithStatus(string $slug, string $status): Article
    {
        return Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->author->id,
            'title' => str_replace('-', ' ', ucfirst($slug)),
            'slug' => $slug,
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => $status,
        ]);
    }

    private function updatePayload(Article $article, string $title): array
    {
        return [
            'magazine_id' => $article->magazine_id,
            'title' => $title,
            'abstract' => $article->abstract,
            'full_text' => $article->full_text,
        ];
    }
}
