<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Magazine;
use App\Models\Permission;
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
        ReviewerAssignment::create([
            'article_id' => $article->id, 'article_version_id' => $initial->id, 'round_number' => 1,
            'reviewer_id' => $reviewer->id, 'assigned_by' => $editor->id, 'status' => 'completed',
            'completed_at' => now(), 'recommendation' => 'minor_revision',
        ]);
        ReviewerAssignment::create([
            'article_id' => $article->id, 'article_version_id' => $revision->id, 'round_number' => 1,
            'reviewer_id' => $reviewer->id, 'assigned_by' => $editor->id, 'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        $manifest = app(ArticleWorkspaceManifestService::class)->manifest($article->fresh(), $editor);
        $versionTabs = collect($manifest['tabs'])->where('type', 'article_version')->values();

        $this->assertSame('Initial Submission (ART-2026-001)', $versionTabs[0]['label']);
        $this->assertSame('ART-2026-001 – R2 (Accepted)', $versionTabs[1]['label']);
        $this->assertSame($revision->id, $manifest['accepted_version_id']);
        $this->assertContains('Reviewer 1 Review', collect($versionTabs[0]['sidebar'])->pluck('label'));
        $this->assertNotContains('Reviewer 1 Review', collect($versionTabs[1]['sidebar'])->pluck('label'));
        $this->assertArrayNotHasKey('next_action', $manifest);
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
