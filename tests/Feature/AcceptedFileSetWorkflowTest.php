<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleFile;
use App\Models\ArticleVersion;
use App\Models\Magazine;
use App\Models\Permission;
use App\Models\ProductionAssignment;
use App\Models\ReviewerAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AcceptedFileSetWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $author;
    private User $editor;
    private User $copyEditor;
    private User $otherCopyEditor;
    private User $reviewer;
    private Magazine $magazine;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['articles.view-own', 'articles.approve'] as $name) {
            Permission::firstOrCreate(['name' => $name], ['module' => 'articles', 'description' => $name]);
        }
        $authorRole = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);
        $editorRole = Role::create(['name' => 'editor', 'display_name' => 'Editor', 'is_system' => true]);
        $copyRole = Role::create(['name' => 'copy_editor', 'display_name' => 'Copy Editor', 'is_system' => true]);
        $reviewerRole = Role::create(['name' => 'reviewer', 'display_name' => 'Reviewer', 'is_system' => true]);
        $authorRole->permissions()->sync(Permission::where('name', 'articles.view-own')->pluck('id'));
        $editorRole->permissions()->sync(Permission::pluck('id'));
        $copyRole->permissions()->sync(Permission::where('name', 'articles.view-own')->pluck('id'));
        $reviewerRole->permissions()->sync(Permission::where('name', 'articles.view-own')->pluck('id'));

        $this->author = User::factory()->create(['role_id' => $authorRole->id]);
        $this->editor = User::factory()->create(['role_id' => $editorRole->id]);
        $this->copyEditor = User::factory()->create(['role_id' => $copyRole->id]);
        $this->otherCopyEditor = User::factory()->create(['role_id' => $copyRole->id]);
        $this->reviewer = User::factory()->create(['role_id' => $reviewerRole->id]);
        $this->magazine = Magazine::create(['title' => 'Accepted Files Journal', 'slug' => 'accepted-files-journal']);
        $this->editor->magazines()->attach($this->magazine->id, ['role' => 'editor']);
    }

    public function test_accepting_r1_includes_only_r1_files_and_copy_editor_uses_them(): void
    {
        $article = $this->article();
        $initial = $this->version($article, 1, ArticleStatus::SUBMITTED, 'Initial Submission');
        $r1 = $this->version($article, 2, ArticleStatus::RESUBMITTED, 'Revised Manuscript', 1);

        $initialManuscript = $this->file($article, $initial, ArticleFile::MANUSCRIPT, 'initial.pdf');
        $initialCover = $this->file($article, $initial, ArticleFile::ADDITIONAL_MANUSCRIPT_FILE, 'cover-v1.pdf', 'Cover Letter');
        $graphicalAbstract = $this->file($article, $initial, ArticleFile::SUPPLEMENTARY, 'graphical-abstract.png', 'Graphical Abstract');
        $r1Manuscript = $this->file($article, $r1, ArticleFile::MANUSCRIPT, 'r1.pdf');
        $r1Cover = $this->file($article, $r1, ArticleFile::ADDITIONAL_MANUSCRIPT_FILE, 'cover-r1.pdf', 'Cover Letter');
        $r1Ethics = $this->file($article, $r1, ArticleFile::ADDITIONAL_MANUSCRIPT_FILE, 'ethics-r1.pdf', 'Ethics Approval');
        $revisionResponse = $this->file($article, $r1, ArticleFile::REVISION_RESPONSE, 'response-r1.pdf');
        $reviewerAttachment = $this->file($article, $r1, ArticleFile::REVIEWED_MANUSCRIPT, 'reviewer.pdf', null, 'reviewer_assignment', 99);
        $failedSupporting = $this->file($article, $r1, ArticleFile::SUPPLEMENTARY, 'failed.csv', 'Dataset', null, null, 'failed');

        Sanctum::actingAs($this->editor);
        $this->postJson("/api/admin/articles/{$article->id}/final-decision", [
            'decision' => 'accepted',
            'decision_source' => 'editor_personal_review',
            'comments_for_author' => 'Accepted R1.',
        ])->assertCreated()
            ->assertJsonPath('article.accepted_file_set.article_version_id', $r1->id);

        $setId = $article->activeAcceptedFileSet()->value('id');
        $acceptedIds = \DB::table('article_accepted_file_set_items')
            ->where('accepted_file_set_id', $setId)
            ->pluck('article_file_id')
            ->all();

        $this->assertEqualsCanonicalizing([$r1Manuscript->id, $r1Cover->id, $r1Ethics->id], $acceptedIds);
        $this->assertNotContains($initialManuscript->id, $acceptedIds);
        $this->assertNotContains($initialCover->id, $acceptedIds);
        $this->assertNotContains($graphicalAbstract->id, $acceptedIds);
        $this->assertNotContains($revisionResponse->id, $acceptedIds);
        $this->assertNotContains($reviewerAttachment->id, $acceptedIds);
        $this->assertNotContains($failedSupporting->id, $acceptedIds);
        $this->assertSame(ArticleStatus::ACCEPTED, $article->fresh()->status);
        $this->assertNotNull($r1->fresh()->accepted_at);

        ProductionAssignment::create([
            'article_id' => $article->id,
            'user_id' => $this->copyEditor->id,
            'role' => 'copy_editor',
            'assigned_by' => $this->editor->id,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($this->copyEditor);
        $workflow = $this->getJson("/api/admin/articles/{$article->id}/workflow")
            ->assertOk()
            ->assertJsonCount(0, 'article.versions')
            ->assertJsonPath('article.accepted_file_set.article_version_id', $r1->id)
            ->assertJsonFragment(['original_name' => 'r1.pdf'])
            ->assertJsonFragment(['original_name' => 'cover-r1.pdf']);
        $this->assertStringNotContainsString('initial.pdf', $workflow->getContent());
        $this->assertStringNotContainsString('graphical-abstract.png', $workflow->getContent());
        $this->assertStringNotContainsString('response-r1.pdf', $workflow->getContent());
        $this->getJson("/api/admin/articles/{$article->id}/versions")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        Sanctum::actingAs($this->otherCopyEditor);
        $this->getJson("/api/admin/articles/{$article->id}/accepted-files")->assertForbidden();

        ReviewerAssignment::create([
            'article_id' => $article->id,
            'reviewer_id' => $this->reviewer->id,
            'assigned_by' => $this->editor->id,
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
        Sanctum::actingAs($this->reviewer);
        $this->getJson("/api/admin/articles/{$article->id}/accepted-files")->assertForbidden();
    }

    public function test_accepting_r2_uses_r2_manuscript_and_preserves_prior_version_links(): void
    {
        $article = $this->article();
        $initial = $this->version($article, 1, ArticleStatus::SUBMITTED, 'Initial Submission');
        $r1 = $this->version($article, 2, ArticleStatus::RESUBMITTED, 'Revised Manuscript', 1);
        $r2 = $this->version($article, 3, ArticleStatus::RESUBMITTED, 'Revised Manuscript', 2);

        $initialManuscript = $this->file($article, $initial, ArticleFile::MANUSCRIPT, 'initial.pdf');
        $r1Manuscript = $this->file($article, $r1, ArticleFile::MANUSCRIPT, 'r1.pdf');
        $r2Manuscript = $this->file($article, $r2, ArticleFile::MANUSCRIPT, 'r2.pdf');
        $r2Additional = $this->file($article, $r2, ArticleFile::ADDITIONAL_MANUSCRIPT_FILE, 'r2-ethics.pdf', 'Ethics Approval');
        $r2Supplementary = $this->file($article, $r2, ArticleFile::SUPPLEMENTARY, 'r2-data.csv', 'Dataset');

        Sanctum::actingAs($this->editor);
        $this->postJson("/api/admin/articles/{$article->id}/final-decision", [
            'decision' => 'accepted',
            'decision_source' => 'editor_personal_review',
            'comments_for_author' => 'Accepted R2.',
        ])->assertCreated()
            ->assertJsonPath('article.accepted_file_set.article_version_id', $r2->id)
            ->assertJsonPath('article.accepted_file_set.accepted_by.id', $this->editor->id);

        $setId = $article->activeAcceptedFileSet()->value('id');
        $acceptedIds = \DB::table('article_accepted_file_set_items')
            ->where('accepted_file_set_id', $setId)
            ->pluck('article_file_id')
            ->all();

        $this->assertEqualsCanonicalizing([$r2Manuscript->id, $r2Additional->id, $r2Supplementary->id], $acceptedIds);
        $this->assertNotContains($initialManuscript->id, $acceptedIds);
        $this->assertNotContains($r1Manuscript->id, $acceptedIds);
        $this->assertSame($initial->id, $initialManuscript->fresh()->article_version_id);
        $this->assertSame($r1->id, $r1Manuscript->fresh()->article_version_id);
        $this->assertNotNull($r2->fresh()->accepted_at);
        $this->assertSame($this->editor->id, $r2->fresh()->accepted_by);
    }

    public function test_acceptance_is_atomic_when_current_version_has_no_clean_manuscript(): void
    {
        $article = $this->article();
        $version = $this->version($article, 1, ArticleStatus::SUBMITTED, 'Initial Submission');
        $this->file($article, $version, ArticleFile::REVISION_RESPONSE, 'response.pdf');

        Sanctum::actingAs($this->editor);
        $this->postJson("/api/admin/articles/{$article->id}/final-decision", [
            'decision' => 'accepted',
            'decision_source' => 'editor_personal_review',
        ])->assertUnprocessable();

        $this->assertSame(ArticleStatus::REVIEW_IN_PROGRESS, $article->fresh()->status);
        $this->assertDatabaseCount('editorial_decisions', 0);
        $this->assertDatabaseCount('article_accepted_file_sets', 0);
        $this->assertNull($version->fresh()->accepted_at);
    }

    private function article(): Article
    {
        return Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->author->id,
            'title' => 'Accepted File Lifecycle',
            'slug' => 'accepted-file-lifecycle-' . uniqid(),
            'abstract' => 'Abstract',
            'full_text' => 'Body',
            'status' => ArticleStatus::REVIEW_IN_PROGRESS,
        ]);
    }

    private function version(Article $article, int $number, string $status, string $label, ?int $revision = null): ArticleVersion
    {
        return ArticleVersion::create([
            'article_id' => $article->id,
            'created_by' => $this->author->id,
            'version_number' => $number,
            'revision_number' => $revision,
            'label' => $label,
            'status_snapshot' => $status,
        ]);
    }

    private function file(
        Article $article,
        ArticleVersion $version,
        string $type,
        string $name,
        ?string $title = null,
        ?string $assignmentType = null,
        ?int $assignmentId = null,
        string $scanStatus = 'clean'
    ): ArticleFile {
        return ArticleFile::create([
            'article_id' => $article->id,
            'article_version_id' => $version->id,
            'uploaded_by' => $this->author->id,
            'assignment_type' => $assignmentType,
            'assignment_id' => $assignmentId,
            'file_type' => $type,
            'file_title' => $title,
            'visibility' => 'author_visible',
            'disk' => 's3',
            'file_path' => 'clean/' . $name,
            'storage_key' => 'clean/' . $name,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'size' => 14,
            'scan_status' => $scanStatus,
        ]);
    }
}
