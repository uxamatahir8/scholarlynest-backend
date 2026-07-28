<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleAcceptedFileSet;
use App\Models\ArticleAcceptedFileSetItem;
use App\Models\ArticleFile;
use App\Models\ArticleVersion;
use App\Models\EditorialDecision;
use App\Models\Magazine;
use App\Models\Permission;
use App\Models\ProductionAssignment;
use App\Models\ReviewerAssignment;
use App\Models\Role;
use App\Models\User;
use App\Services\ArticleWorkspaceManifestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArticleWorkspaceManifestTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_manifest_names_versions_marks_backend_accepted_version_and_scopes_reviews(): void
    {
        [$article, $editor, $reviewer] = $this->workspaceFixture();
        $initial = ArticleVersion::create([
            'article_id' => $article->id, 'created_by' => $article->user_id, 'version_number' => 1,
            'status_snapshot' => 'submitted', 'screening_status' => 'passed', 'submitted_at' => now()->subDay(),
        ]);
        $revision = ArticleVersion::create([
            'article_id' => $article->id, 'created_by' => $article->user_id, 'version_number' => 2,
            'revision_number' => 1, 'status_snapshot' => 'under_review', 'screening_status' => 'passed',
            'submitted_at' => now(), 'accepted_marker' => 1, 'accepted_at' => now(),
        ]);
        $article->update(['current_version_id' => $revision->id, 'accepted_version_id' => $revision->id, 'status' => 'accepted']);
        $initialReview = ReviewerAssignment::create([
            'article_id' => $article->id, 'article_version_id' => $initial->id, 'round_number' => 1,
            'reviewer_id' => $reviewer->id, 'assigned_by' => $editor->id, 'status' => 'completed',
            'completed_at' => now(), 'recommendation' => 'minor_revision',
        ]);
        $revisionReview = ReviewerAssignment::create([
            'article_id' => $article->id, 'article_version_id' => $revision->id, 'round_number' => 1,
            'reviewer_id' => $reviewer->id, 'assigned_by' => $editor->id, 'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        $manifest = app(ArticleWorkspaceManifestService::class)->manifest($article->fresh(), $editor);
        $versionTabs = collect($manifest['tabs'])->where('type', 'article_version')->values();

        $this->assertSame('Initial Submission (ART-2026-001)', $versionTabs[0]['label']);
        $this->assertSame('ART-2026-001 – R2 (Accepted)', $versionTabs[1]['label']);
        $this->assertSame(['code' => 'submitted', 'label' => 'Submitted', 'screening' => 'passed'], $versionTabs[0]['status']);
        $this->assertSame(['code' => 'accepted', 'label' => 'Accepted', 'screening' => 'passed'], $versionTabs[1]['status']);
        $this->assertFalse($versionTabs[0]['is_accepted']);
        $this->assertTrue($versionTabs[1]['is_accepted']);
        $this->assertSame($revision->id, $manifest['accepted_version_id']);
        $this->assertContains('Reviewer 1 Review', collect($versionTabs[0]['sidebar'])->pluck('label'));
        $this->assertNotContains('Reviewer 1 Review', collect($versionTabs[1]['sidebar'])->pluck('label'));
        $this->assertArrayNotHasKey('next_action', $manifest);

        Sanctum::actingAs($editor);
        $this->getJson("/api/admin/articles/{$article->id}/versions/{$initial->id}/reviewers?review_round=1")
            ->assertOk()
            ->assertJsonPath('data.version_id', $initial->id)
            ->assertJsonCount(1, 'data.reviewer_assignments')
            ->assertJsonPath('data.reviewer_assignments.0.id', $initialReview->id);
        $this->getJson("/api/admin/articles/{$article->id}/versions/{$revision->id}/reviewers?review_round=1")
            ->assertOk()
            ->assertJsonPath('data.version_id', $revision->id)
            ->assertJsonCount(1, 'data.reviewer_assignments')
            ->assertJsonPath('data.reviewer_assignments.0.id', $revisionReview->id);
    }

    public function test_direct_publication_manifest_has_no_editorial_or_reviewer_tabs(): void
    {
        [$article] = $this->workspaceFixture();
        $superRole = Role::create(['name' => 'super_admin', 'display_name' => 'Super Admin', 'is_system' => true]);
        $super = User::factory()->create(['role_id' => $superRole->id]);
        $article->update(['submission_mode' => 'direct_publication']);
        $version = ArticleVersion::create([
            'article_id' => $article->id, 'created_by' => $article->user_id, 'version_number' => 1,
            'status_snapshot' => 'draft', 'submitted_at' => now(),
        ]);
        $article->update(['current_version_id' => $version->id]);

        $manifest = app(ArticleWorkspaceManifestService::class)->manifest($article->fresh(), $super);
        $types = collect($manifest['tabs'])->pluck('type');

        $this->assertSame(['article_version', 'workflow_history', 'communication'], $types->all());
        $this->assertNotContains('final_editorial_decision', $types);
        $this->assertNotContains('copy_editing', $types);
        $this->assertNotContains('proofreading', $types);
    }

    public function test_assigned_copy_editor_receives_only_the_safe_accepted_manuscript_workspace(): void
    {
        [$article, $editor, $reviewer] = $this->workspaceFixture();
        $copyRole = Role::create(['name' => 'copy_editor', 'display_name' => 'Copy Editor', 'is_system' => true]);
        $copyRole->permissions()->sync(Permission::where('name', 'articles.view-own')->pluck('id'));
        $copyEditor = User::factory()->create(['role_id' => $copyRole->id]);
        $otherCopyEditor = User::factory()->create(['role_id' => $copyRole->id]);
        $outsideCopyEditor = User::factory()->create(['role_id' => $copyRole->id]);
        $otherCopyEditor->magazines()->attach($article->magazine_id, ['role' => 'copy_editor']);

        $otherMagazine = Magazine::create(['title' => 'Other Journal', 'slug' => 'other-journal', 'description' => 'Test']);
        $outsideCopyEditor->magazines()->attach($otherMagazine->id, ['role' => 'copy_editor']);

        $initial = ArticleVersion::create([
            'article_id' => $article->id, 'created_by' => $article->user_id, 'version_number' => 1,
            'label' => 'Initial Submission', 'status_snapshot' => 'submitted',
            'metadata_snapshot' => ['title' => 'Initial private title', 'abstract' => 'Initial abstract'],
        ]);
        $accepted = ArticleVersion::create([
            'article_id' => $article->id, 'created_by' => $article->user_id, 'version_number' => 2,
            'revision_number' => 1, 'revision_tracking_code' => 'ART-2026-001-R1', 'label' => 'Revised Manuscript',
            'status_snapshot' => 'under_review', 'accepted_marker' => 1, 'accepted_at' => now(),
            'change_summary' => 'Accepted revision changes.', 'author_response' => 'Production-safe response.',
            'metadata_snapshot' => [
                'title' => 'Accepted version title',
                'abstract' => 'Accepted version abstract',
                'keywords' => ['copyediting', 'production'],
                'article_type' => 'Research Article',
                'article_category' => 'Original Research',
                'subject_area' => 'Computer Science',
                'language' => 'English',
                'funding_statement' => 'No external funding.',
                'authors' => [['name' => 'Accepted Author', 'affiliation' => 'Manifest University', 'is_corresponding' => true, 'author_order' => 1]],
            ],
        ]);
        $article->update([
            'title' => 'Later mutable article title', 'abstract' => 'Later mutable abstract',
            'current_version_id' => $accepted->id, 'accepted_version_id' => $accepted->id, 'status' => 'accepted',
        ]);
        $file = ArticleFile::create([
            'article_id' => $article->id, 'article_version_id' => $accepted->id, 'uploaded_by' => $article->user_id,
            'file_type' => ArticleFile::MANUSCRIPT, 'visibility' => 'workflow', 'disk' => 'local',
            'file_path' => 'articles/accepted.docx', 'storage_key' => 'articles/accepted.docx',
            'original_name' => 'accepted.docx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => 1024, 'scan_status' => 'clean',
        ]);
        $set = ArticleAcceptedFileSet::create([
            'article_id' => $article->id, 'article_version_id' => $accepted->id, 'accepted_by' => $editor->id,
            'accepted_at' => now(), 'selection_policy' => ArticleAcceptedFileSet::POLICY_VERSION_LOCAL, 'active_marker' => 1,
        ]);
        ArticleAcceptedFileSetItem::create([
            'accepted_file_set_id' => $set->id, 'article_file_id' => $file->id,
            'source_version_id' => $accepted->id, 'accepted_role' => 'manuscript',
        ]);
        ReviewerAssignment::create([
            'article_id' => $article->id, 'article_version_id' => $accepted->id, 'round_number' => 1,
            'reviewer_id' => $reviewer->id, 'assigned_by' => $editor->id, 'status' => 'completed',
            'completed_at' => now(), 'confidential_comments' => 'Never expose this review.',
        ]);
        EditorialDecision::create([
            'article_id' => $article->id, 'article_version_id' => $accepted->id, 'decision_by' => $editor->id,
            'decision' => 'accepted', 'decision_source' => 'editor', 'decision_date' => now(),
            'internal_notes' => 'Never expose this editorial note.',
        ]);
        $assignment = ProductionAssignment::create([
            'article_id' => $article->id, 'article_version_id' => $accepted->id, 'accepted_file_set_id' => $set->id,
            'user_id' => $copyEditor->id, 'role' => 'copy_editor', 'assigned_by' => $editor->id, 'status' => 'pending',
        ]);
        $this->assertFalse($copyEditor->magazines()->whereKey($article->magazine_id)->exists());
        $manifest = app(ArticleWorkspaceManifestService::class)->manifest($article->fresh(), $copyEditor);
        $this->assertSame(['accepted_manuscript', 'copy_editing', 'workflow_history', 'communication'], collect($manifest['tabs'])->pluck('type')->all());
        $this->assertSame('copyeditor-manuscript', $manifest['tabs'][0]['key']);
        $this->assertSame($accepted->id, $manifest['tabs'][0]['accepted_version_id']);
        $this->assertEmpty(collect($manifest['tabs'])->where('type', 'article_version'));

        Sanctum::actingAs($copyEditor);
        $workflow = $this->getJson("/api/admin/articles/{$article->id}/workflow")
            ->assertOk()
            ->assertJsonPath('workflow_manifest.tabs.0.type', 'accepted_manuscript')
            ->assertJsonCount(0, 'article.versions');
        $this->assertStringNotContainsString('Initial private title', $workflow->getContent());

        $acceptedResponse = $this->getJson("/api/admin/articles/{$article->id}/accepted-manuscript")
            ->assertOk()
            ->assertJsonPath('data.article.title', 'Accepted version title')
            ->assertJsonPath('data.article.abstract', 'Accepted version abstract')
            ->assertJsonPath('data.accepted_version.id', $accepted->id)
            ->assertJsonPath('data.files.manuscript.0.file.original_name', 'accepted.docx')
            ->assertJsonMissingPath('data.reviewer_assignments')
            ->assertJsonMissingPath('data.editorial_decisions');
        $this->assertStringNotContainsString('Later mutable article title', $acceptedResponse->getContent());
        $this->assertStringNotContainsString('Never expose this review', $acceptedResponse->getContent());
        $this->assertStringNotContainsString('Never expose this editorial note', $acceptedResponse->getContent());

        Sanctum::actingAs($otherCopyEditor);
        $this->getJson("/api/admin/articles/{$article->id}/accepted-manuscript")->assertForbidden();
        $this->getJson("/api/admin/articles/{$article->id}/workflow")->assertForbidden();

        Sanctum::actingAs($outsideCopyEditor);
        $this->getJson("/api/admin/articles/{$article->id}/accepted-manuscript")->assertForbidden();
        $this->getJson("/api/admin/articles/{$article->id}/workflow")->assertForbidden();

        $assignment->update(['status' => 'completed', 'completed_at' => now()]);
        Sanctum::actingAs($copyEditor);
        $this->getJson("/api/admin/articles/{$article->id}/accepted-manuscript")->assertForbidden();
    }

    public function test_direct_publication_workspace_rejects_non_publisher_editorial_roles(): void
    {
        [$article, $editor] = $this->workspaceFixture();
        $article->update(['submission_mode' => 'direct_publication']);
        Sanctum::actingAs($editor);

        $this->getJson("/api/admin/articles/{$article->id}/workspace-manifest")->assertNotFound();
        $this->getJson("/api/admin/articles/{$article->id}/workflow")->assertNotFound();
    }

    private function workspaceFixture(): array
    {
        $authorRole = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);
        $editorRole = Role::create(['name' => 'editor', 'display_name' => 'Editor', 'is_system' => true]);
        $reviewerRole = Role::create(['name' => 'reviewer', 'display_name' => 'Reviewer', 'is_system' => true]);
        foreach (['articles.view-own', 'articles.approve'] as $name) {
            Permission::create(['name' => $name, 'module' => 'articles', 'description' => $name]);
        }
        $editorRole->permissions()->sync(Permission::pluck('id'));
        $author = User::factory()->create(['role_id' => $authorRole->id]);
        $editor = User::factory()->create(['role_id' => $editorRole->id]);
        $reviewer = User::factory()->create(['role_id' => $reviewerRole->id]);
        $magazine = Magazine::create(['title' => 'Manifest Journal', 'slug' => 'manifest-journal', 'description' => 'Test']);
        $editor->magazines()->attach($magazine->id, ['role' => 'editor']);
        $article = Article::create([
            'magazine_id' => $magazine->id, 'user_id' => $author->id, 'tracking_code' => 'ART-2026-001',
            'title' => 'Workspace Article', 'slug' => 'workspace-article', 'abstract' => 'Abstract',
            'full_text' => 'Text', 'status' => 'submitted',
        ]);

        return [$article, $editor, $reviewer];
    }
}
