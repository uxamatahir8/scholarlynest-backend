<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleFile;
use App\Models\Magazine;
use App\Models\MediaUploadSession;
use App\Models\Role;
use App\Models\User;
use App\Services\ArticleFileCleanupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArticleFileAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $author;
    private Article $article;
    private ArticleFileCleanupService $cleanup;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        $role = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);
        $this->author = User::factory()->create(['role_id' => $role->id]);
        $magazine = Magazine::create(['title' => 'Cleanup Journal', 'slug' => 'cleanup-journal']);
        $this->article = Article::create([
            'magazine_id' => $magazine->id, 'user_id' => $this->author->id,
            'title' => 'Cleanup Article', 'slug' => 'cleanup-article', 'abstract' => 'Abstract',
            'full_text' => '', 'status' => 'draft',
        ]);
        $this->cleanup = app(ArticleFileCleanupService::class);
    }

    public function test_dry_run_does_not_modify_records(): void
    {
        [$first, $second] = $this->duplicatePair();
        $this->artisan('article-files:audit')->assertSuccessful();
        $this->assertDatabaseHas('article_files', ['id' => $first->id]);
        $this->assertDatabaseHas('article_files', ['id' => $second->id]);
        $this->assertDatabaseCount('article_file_cleanup_logs', 0);
    }

    public function test_same_upload_session_duplicate_is_detected(): void
    {
        Schema::table('article_files', fn (Blueprint $table) => $table->dropUnique('article_files_upload_session_unique'));
        $upload = $this->upload('clean/same-upload.pdf');
        $first = $this->file('clean/same-upload.pdf', ['media_upload_session_id' => $upload->id]);
        $second = $this->file('clean/same-upload.pdf', ['media_upload_session_id' => $upload->id]);
        $audit = $this->cleanup->audit();
        $this->assertSame([$first->id, $second->id], $audit['duplicate_groups'][0]['record_ids']);
    }

    public function test_same_storage_reference_duplicate_is_detected(): void
    {
        [$first, $second] = $this->duplicatePair();
        $this->assertSame([$first->id, $second->id], $this->cleanup->audit()['duplicate_groups'][0]['record_ids']);
    }

    public function test_same_filename_with_distinct_uploads_is_not_duplicate(): void
    {
        $this->file('clean/one.pdf', ['original_name' => 'same.pdf']);
        $this->file('clean/two.pdf', ['original_name' => 'same.pdf']);
        $this->assertSame([], $this->cleanup->audit()['duplicate_groups']);
    }

    public function test_oldest_equivalent_clean_record_is_canonical(): void
    {
        [$first] = $this->duplicatePair();
        $this->assertSame($first->id, $this->cleanup->audit()['duplicate_groups'][0]['canonical_id']);
    }

    public function test_invalid_failed_placeholder_is_removed_in_apply_mode(): void
    {
        $failed = $this->file('incoming/rejected.pdf', ['scan_status' => 'rejected']);
        $this->cleanup->apply($this->cleanup->audit());
        $this->assertDatabaseMissing('article_files', ['id' => $failed->id]);
        $this->assertDatabaseHas('article_file_cleanup_logs', ['removed_article_file_id' => $failed->id, 'storage_deleted' => false]);
    }

    public function test_accepted_file_set_reference_is_migrated_to_canonical(): void
    {
        [$canonical, $duplicate] = $this->duplicatePair();
        $version = $this->version();
        $setId = DB::table('article_accepted_file_sets')->insertGetId([
            'article_id' => $this->article->id, 'article_version_id' => $version,
            'accepted_by' => $this->author->id, 'accepted_at' => now(),
            'selection_policy' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('article_accepted_file_set_items')->insert([
            'accepted_file_set_id' => $setId, 'article_file_id' => $duplicate->id,
            'source_version_id' => $version, 'accepted_role' => 'manuscript',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $audit = $this->cleanup->audit();
        $this->assertSame($duplicate->id, $audit['duplicate_groups'][0]['canonical_id']);
        $this->cleanup->apply($audit);
        $this->assertDatabaseHas('article_files', ['id' => $duplicate->id]);
        $this->assertDatabaseMissing('article_files', ['id' => $canonical->id]);
    }

    public function test_production_assignment_reference_is_preserved_on_canonical_record(): void
    {
        [$canonical, $duplicate] = $this->duplicatePair();
        $canonical->update(['scan_status' => 'rejected']);
        $duplicate->update(['assignment_type' => 'production_assignment', 'assignment_id' => 77]);
        $this->cleanup->apply($this->cleanup->audit());
        $this->assertDatabaseHas('article_files', [
            'id' => $duplicate->id, 'assignment_type' => 'production_assignment', 'assignment_id' => 77,
        ]);
    }

    public function test_conflicting_version_references_require_manual_review(): void
    {
        [$first, $second] = $this->duplicatePair();
        $first->update(['article_version_id' => $this->version()]);
        $second->update(['article_version_id' => $this->version(2)]);
        $group = $this->cleanup->audit()['duplicate_groups'][0];
        $this->assertFalse($group['safe']);
        $this->assertSame([], $this->cleanup->audit()['actions']);
    }

    public function test_shared_storage_object_is_not_deleted(): void
    {
        [$canonical, $duplicate] = $this->duplicatePair();
        $this->cleanup->apply($this->cleanup->audit());
        Storage::disk('s3')->assertExists($canonical->storage_key);
        $this->assertDatabaseMissing('article_files', ['id' => $duplicate->id]);
    }

    public function test_apply_mode_is_idempotent(): void
    {
        $this->duplicatePair();
        $first = $this->cleanup->apply($this->cleanup->audit());
        $second = $this->cleanup->apply($this->cleanup->audit());
        $this->assertCount(1, $first['applied']);
        $this->assertCount(0, $second['applied']);
        $this->assertDatabaseCount('article_files', 1);
    }

    public function test_second_audit_has_no_safe_duplicates(): void
    {
        $this->duplicatePair();
        $this->cleanup->apply($this->cleanup->audit());
        $this->assertSame([], $this->cleanup->audit()['duplicate_groups']);
    }

    public function test_workflow_serialization_returns_only_canonical_record(): void
    {
        $this->duplicatePair();
        $this->cleanup->apply($this->cleanup->audit());
        $files = app(\App\Http\Controllers\ArticleFileController::class)
            ->filterVisibleFiles($this->author, $this->article->fresh()->files);
        $this->assertCount(1, $files);
    }

    public function test_valid_version_history_is_unchanged(): void
    {
        $version = $this->version();
        $historical = $this->file('clean/history.pdf', ['article_version_id' => $version]);
        $this->duplicatePair();
        $this->cleanup->apply($this->cleanup->audit());
        $this->assertDatabaseHas('article_files', ['id' => $historical->id, 'article_version_id' => $version]);
    }

    public function test_distinct_valid_primary_manuscripts_are_reported_for_manual_review(): void
    {
        $version = $this->version();
        $first = $this->file('clean/version-a.pdf', ['article_version_id' => $version]);
        $second = $this->file('clean/version-b.pdf', ['article_version_id' => $version]);
        DB::table('article_versions')->where('id', $version)->update(['manuscript_file_id' => $first->id]);

        $record = $this->cleanup->audit()['multiple_primary_manuscripts'][0];
        $this->assertSame('multiple_primary_manuscripts', $record['category']);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $record['manuscript_article_file_ids']);
        $this->assertSame($first->id, $record['recommended_canonical_manuscript']);
        $this->assertTrue($record['manual_review_required']);
    }

    public function test_same_storage_primary_duplicates_are_reported_as_safe_cleanup_candidates(): void
    {
        $version = $this->version();
        [$first, $second] = $this->duplicatePair();
        $first->update(['article_version_id' => $version]);
        $second->update(['article_version_id' => $version]);
        DB::table('article_versions')->where('id', $version)->update(['manuscript_file_id' => $second->id]);

        $record = $this->cleanup->audit()['multiple_primary_manuscripts'][0];
        $this->assertFalse($record['manual_review_required']);
        $this->assertContains($record['recommended_canonical_manuscript'], [$first->id, $second->id]);
    }

    private function duplicatePair(): array
    {
        return [$this->file('clean/shared.pdf'), $this->file('clean/shared.pdf')];
    }

    private function file(string $key, array $overrides = []): ArticleFile
    {
        Storage::disk('s3')->put($key, 'file bytes');
        return ArticleFile::create(array_merge([
            'article_id' => $this->article->id, 'uploaded_by' => $this->author->id,
            'file_type' => ArticleFile::MANUSCRIPT, 'visibility' => 'author_visible',
            'disk' => 's3', 'file_path' => $key, 'storage_key' => $key,
            'original_name' => 'manuscript.pdf', 'mime_type' => 'application/pdf',
            'size' => 10, 'scan_status' => 'clean',
        ], $overrides));
    }

    private function upload(string $key): MediaUploadSession
    {
        return MediaUploadSession::create([
            'user_id' => $this->author->id, 'purpose' => 'article_manuscript',
            'attachable_type' => Article::class, 'attachable_id' => $this->article->id,
            'original_filename' => 'manuscript.pdf', 'safe_display_filename' => 'manuscript.pdf',
            'expected_size_bytes' => 10, 'disk' => 's3', 's3_incoming_key' => 'incoming/'.uniqid().'.pdf',
            's3_clean_key' => $key, 'upload_mode' => 'single', 'status' => MediaUploadSession::STATUS_CLEAN,
            'expires_at' => now()->addHour(),
        ]);
    }

    private function version(int $number = 1): int
    {
        return DB::table('article_versions')->insertGetId([
            'article_id' => $this->article->id, 'created_by' => $this->author->id,
            'version_number' => $number, 'status_snapshot' => 'submitted',
            'metadata_snapshot' => json_encode([]), 'file_snapshot' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
