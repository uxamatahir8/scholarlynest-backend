<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleFile;
use App\Models\Magazine;
use App\Models\MediaUploadSession;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArticleFileWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $editor;
    private User $reviewer;
    private User $author;
    private Magazine $magazine;
    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create(['name' => 'super_admin', 'display_name' => 'Super Admin', 'is_system' => true]);
        $editorRole = Role::create(['name' => 'editor', 'display_name' => 'Editor', 'is_system' => true]);
        $reviewerRole = Role::create(['name' => 'reviewer', 'display_name' => 'Reviewer', 'is_system' => true]);
        $authorRole = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);

        foreach (['articles.view-own', 'articles.edit-own', 'articles.approve', 'articles.manage-assets'] as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission],
                ['module' => 'articles', 'description' => $permission]
            );
        }

        $editorRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.approve'])->pluck('id'));
        $reviewerRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.edit-own'])->pluck('id'));
        $authorRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.edit-own', 'articles.manage-assets'])->pluck('id'));

        $this->admin = User::factory()->create(['role_id' => $adminRole->id]);
        $this->editor = User::factory()->create(['role_id' => $editorRole->id]);
        $this->reviewer = User::factory()->create(['role_id' => $reviewerRole->id]);
        $this->author = User::factory()->create(['role_id' => $authorRole->id]);

        $this->magazine = Magazine::create([
            'title' => 'File Magazine',
            'slug' => 'file-magazine',
            'description' => 'File workflow magazine',
        ]);

        $this->editor->magazines()->attach($this->magazine->id, ['role' => 'editor']);
        $this->reviewer->magazines()->attach($this->magazine->id, ['role' => 'reviewer']);

        $this->article = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->author->id,
            'title' => 'File Article',
            'slug' => 'file-article',
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => ArticleStatus::SUBMITTED,
        ]);

        Storage::fake('public');
    }

    public function test_editor_can_upload_plagiarism_report_during_screening(): void
    {
        Sanctum::actingAs($this->editor);

        $upload = $this->cleanUpload($this->editor, 'article_plagiarism_report', 'similarity-report.pdf');

        $this->postJson("/api/admin/articles/{$this->article->id}/screen", [
            'decision' => 'send_to_review',
            'plagiarism_status' => 'clear',
            'plagiarism_score' => 8.5,
            'comments' => 'Looks clean.',
            'plagiarism_report_upload_id' => $upload->id,
        ])->assertStatus(200)
            ->assertJsonPath('file.file_type', ArticleFile::PLAGIARISM_REPORT);

        $this->assertDatabaseHas('article_files', [
            'article_id' => $this->article->id,
            'file_type' => ArticleFile::PLAGIARISM_REPORT,
            'original_name' => 'similarity-report.pdf',
        ]);

        $this->article->refresh();
        $this->assertEquals('clear', $this->article->plagiarism_status);
        $this->assertEquals('8.50', (string) $this->article->plagiarism_score);
        $this->assertNotNull($this->article->plagiarism_report_path);
    }

    public function test_reviewer_uploads_reviewed_manuscript_and_author_cannot_download_it(): void
    {
        Sanctum::actingAs($this->editor);
        $assignmentId = $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'reviewer_id' => $this->reviewer->id,
        ])->json('assignment.id');

        Sanctum::actingAs($this->reviewer);
        $this->postJson("/api/admin/reviewer-assignments/{$assignmentId}/submit-review", [
            'scorecard' => ['originality' => 4],
            'recommendation' => 'minor_revision',
            'comments_for_author' => 'Please revise.',
            'reviewed_manuscript_upload_id' => $this->cleanUpload($this->reviewer, 'article_reviewed_manuscript', 'reviewed.docx')->id,
        ])->assertStatus(200)
            ->assertJsonPath('file.file_type', ArticleFile::REVIEWED_MANUSCRIPT);

        $file = ArticleFile::where('file_type', ArticleFile::REVIEWED_MANUSCRIPT)->firstOrFail();

        $this->getJson("/api/articles/files/{$file->id}/download")
            ->assertRedirect();

        Sanctum::actingAs($this->author);
        $this->getJson("/api/articles/files/{$file->id}/download")
            ->assertStatus(403);
    }

    public function test_workflow_context_filters_files_by_role(): void
    {
        Sanctum::actingAs($this->editor);

        $this->postJson("/api/admin/articles/{$this->article->id}/screen", [
            'decision' => 'send_to_review',
            'plagiarism_status' => 'clear',
            'plagiarism_score' => 3,
            'plagiarism_report_upload_id' => $this->cleanUpload($this->editor, 'article_plagiarism_report', 'report.pdf')->id,
        ])->assertStatus(200);

        $this->getJson("/api/admin/articles/{$this->article->id}/workflow")
            ->assertStatus(200)
            ->assertJsonPath('files.0.file_type', ArticleFile::PLAGIARISM_REPORT);

        Sanctum::actingAs($this->author);
        $this->getJson("/api/admin/articles/{$this->article->id}/workflow")
            ->assertStatus(200)
            ->assertJsonCount(0, 'files');
    }

    private function cleanUpload(User $user, string $purpose, string $filename): MediaUploadSession
    {
        $key = 'dev/clean/test/' . $purpose . '/' . $filename;
        Storage::disk('s3')->put($key, 'clean test file');

        return MediaUploadSession::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'attachable_type' => Article::class,
            'attachable_id' => $this->article->id,
            'original_filename' => $filename,
            'safe_display_filename' => $filename,
            'expected_size_bytes' => 16,
            'declared_mime_type' => str_ends_with($filename, '.pdf') ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'disk' => 's3',
            's3_incoming_key' => 'dev/incoming/test/' . $purpose . '/' . $filename,
            's3_clean_key' => $key,
            'upload_mode' => 'single',
            'status' => MediaUploadSession::STATUS_CLEAN,
            'detected_mime_type' => str_ends_with($filename, '.pdf') ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'checksum_sha256' => str_repeat('a', 64),
            'scan_engine' => 'fake-clamav',
            'scan_status' => 'clean',
            'scanned_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
    }
}
