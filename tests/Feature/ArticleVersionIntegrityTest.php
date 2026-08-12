<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Magazine;
use App\Models\Role;
use App\Models\User;
use App\Services\ArticleVersionIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleVersionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_detects_missing_r1_duplicate_revisions_and_parent_mismatch(): void
    {
        $article = $this->article();
        $initial = $this->version($article, 1, 0);
        $r2a = $this->version($article, 2, 2, $initial->id);
        $this->version($article, 3, 2, $initial->id);

        $result = app(ArticleVersionIntegrityService::class)->inspect($article);
        $codes = collect($result['issues'])->pluck('code');

        $this->assertContains('missing_r1', $codes);
        $this->assertContains('duplicate_revision_number', $codes);
        $this->assertContains('revision_gap', $codes);
        $this->assertContains('parent_version_mismatch', $codes);
        $this->assertFalse($result['valid']);
        $this->assertSame('version-'.$r2a->id, $result['versions'][1]['expected_tab_key']);
    }

    public function test_article_scoped_repair_supports_dry_run_and_audits_changes(): void
    {
        $article = $this->article();
        $initial = $this->version($article, 1, null, null, 'Initial Submission');
        $revision = $this->version($article, 2, 2, $initial->id, 'Revised Manuscript');
        $article->update(['current_version_id' => $revision->id]);

        $dryRun = app(ArticleVersionIntegrityService::class)->repair($article, true);
        $this->assertCount(2, $dryRun['changes']);
        $this->assertNull($initial->fresh()->revision_number);
        $this->assertSame(2, $revision->fresh()->revision_number);

        $result = app(ArticleVersionIntegrityService::class)->repair($article, false);
        $this->assertSame(0, $initial->fresh()->revision_number);
        $this->assertSame(1, $revision->fresh()->revision_number);
        $this->assertTrue($result['after']['valid']);
        $this->assertDatabaseHas('article_audit_logs', [
            'article_id' => $article->id,
            'event' => 'article.version_integrity_repaired',
        ]);
    }

    public function test_repair_reports_ambiguous_records_without_rewriting_them(): void
    {
        $article = $this->article();
        $initial = $this->version($article, 1, null, null, 'Initial Submission');
        $published = $this->version($article, 2, null, $initial->id, 'Published Manuscript');

        $result = app(ArticleVersionIntegrityService::class)->repair($article, false);

        $this->assertNotEmpty($result['ambiguous']);
        $this->assertNull($published->fresh()->revision_number);
        $this->assertDatabaseMissing('article_audit_logs', ['event' => 'article.version_integrity_repaired']);
    }

    private function article(): Article
    {
        $role = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);
        $author = User::factory()->create(['role_id' => $role->id]);
        $magazine = Magazine::create(['title' => 'Integrity Journal', 'slug' => 'integrity-journal', 'description' => 'Test']);

        return Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $author->id,
            'tracking_code' => 'SN-2026-INTEGRITY',
            'title' => 'Integrity Article',
            'slug' => 'integrity-article',
            'abstract' => 'Abstract',
            'full_text' => 'Text',
            'status' => 'submitted',
        ]);
    }

    private function version(Article $article, int $versionNumber, ?int $revisionNumber, ?int $parentId = null, string $label = 'Revised Manuscript'): ArticleVersion
    {
        return ArticleVersion::create([
            'article_id' => $article->id,
            'parent_version_id' => $parentId,
            'created_by' => $article->user_id,
            'version_number' => $versionNumber,
            'revision_number' => $revisionNumber,
            'label' => $label,
            'status_snapshot' => 'submitted',
            'submitted_at' => now()->addMinutes($versionNumber),
        ]);
    }
}
