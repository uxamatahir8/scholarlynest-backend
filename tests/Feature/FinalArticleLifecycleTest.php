<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleFile;
use App\Models\ArticleVersion;
use App\Models\Magazine;
use App\Models\MagazineIssue;
use App\Models\Permission;
use App\Models\ProofRound;
use App\Models\Role;
use App\Models\User;
use App\Services\AcceptedFileSetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinalArticleLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    private User $reviewer;

    private User $author;

    private User $publisher;

    private User $copyEditor;

    private Magazine $magazine;

    private Article $article;

    private ArticleVersion $version;

    private ArticleFile $manuscript;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $view = Permission::create(['name' => 'articles.view-own', 'module' => 'articles']);
        $approve = Permission::create(['name' => 'articles.approve', 'module' => 'articles']);
        $roles = collect(['author', 'editor', 'reviewer', 'publisher', 'copy_editor'])->mapWithKeys(fn ($name) => [$name => Role::create(['name' => $name, 'display_name' => ucfirst($name), 'is_system' => true])]);
        $roles['editor']->permissions()->sync([$view->id, $approve->id]);
        $roles['reviewer']->permissions()->sync([$view->id]);
        $roles['publisher']->permissions()->sync([$view->id, $approve->id]);
        $roles['author']->permissions()->sync([$view->id]);
        $roles['copy_editor']->permissions()->sync([$view->id]);
        $this->editor = User::factory()->create(['role_id' => $roles['editor']->id]);
        $this->reviewer = User::factory()->create(['role_id' => $roles['reviewer']->id]);
        $this->author = User::factory()->create(['role_id' => $roles['author']->id]);
        $this->publisher = User::factory()->create(['role_id' => $roles['publisher']->id]);
        $this->copyEditor = User::factory()->create(['role_id' => $roles['copy_editor']->id]);
        $this->magazine = Magazine::create(['title' => 'Final Lifecycle Journal', 'slug' => 'final-lifecycle-journal', 'description' => 'Test']);
        $this->editor->magazines()->attach($this->magazine->id, ['role' => 'editor']);
        $this->publisher->magazines()->attach($this->magazine->id, ['role' => 'publisher']);
        $this->article = Article::create(['magazine_id' => $this->magazine->id, 'user_id' => $this->author->id, 'title' => 'Version Scoped Work', 'slug' => 'version-scoped-work', 'abstract' => 'Abstract', 'full_text' => '', 'status' => ArticleStatus::SUBMITTED]);
        $this->version = ArticleVersion::create(['article_id' => $this->article->id, 'created_by' => $this->author->id, 'version_number' => 1, 'label' => 'Initial Submission', 'status_snapshot' => ArticleStatus::SUBMITTED, 'submitted_at' => now(), 'locked_at' => now()]);
        $this->article->update(['current_version_id' => $this->version->id]);
        app(\App\Services\ArticleReviewRoundService::class)->ensureForSubmittedVersion($this->article->fresh(), $this->version->fresh(), $this->editor);
        $this->manuscript = ArticleFile::create(['article_id' => $this->article->id, 'article_version_id' => $this->version->id, 'uploaded_by' => $this->author->id, 'file_type' => ArticleFile::MANUSCRIPT, 'visibility' => 'author_visible', 'file_path' => 'clean/final-lifecycle.pdf', 'storage_key' => 'clean/final-lifecycle.pdf', 'original_name' => 'final-lifecycle.pdf', 'safe_original_name' => 'manuscript.pdf', 'mime_type' => 'application/pdf', 'size' => 100, 'scan_status' => 'clean']);
        $this->version->update(['manuscript_file_id' => $this->manuscript->id]);
    }

    public function test_screening_gate_and_idempotent_version_scoped_invitation(): void
    {
        Sanctum::actingAs($this->editor);
        $round = $this->version->reviewRounds()->firstOrFail();
        $payload = ['article_version_id' => $this->version->id, 'review_round_id' => $round->id, 'round_number' => 1, 'reviewer_id' => $this->reviewer->id];
        $this->withHeader('Idempotency-Key', 'invite-before-screen')->postJson("/api/admin/lifecycle/articles/{$this->article->id}/reviewer-invitations", $payload)->assertConflict();
        $this->withHeader('Idempotency-Key', 'screen-v1')->postJson("/api/admin/lifecycle/articles/{$this->article->id}/screen", ['article_version_id' => $this->version->id, 'decision' => 'pass'])->assertOk()->assertJsonPath('result.version_id', $this->version->id);
        $response = $this->withHeader('Idempotency-Key', 'invite-v1-reviewer')->postJson("/api/admin/lifecycle/articles/{$this->article->id}/reviewer-invitations", $payload)->assertOk();
        $response->assertJsonPath('result.article_version_id', $this->version->id);
        $this->withHeader('Idempotency-Key', 'invite-v1-reviewer')->postJson("/api/admin/lifecycle/articles/{$this->article->id}/reviewer-invitations", $payload)->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->assertDatabaseCount('reviewer_assignments', 1);
    }

    public function test_acceptance_pins_exactly_one_version_and_file_set(): void
    {
        $this->version->update(['screening_status' => 'passed']);
        Sanctum::actingAs($this->editor);
        $this->withHeader('Idempotency-Key', 'accept-v1')->postJson("/api/admin/lifecycle/articles/{$this->article->id}/editorial-decisions", ['article_version_id' => $this->version->id, 'decision' => 'accepted', 'decision_source' => 'editor_personal_review'])->assertOk();
        $this->assertSame($this->version->id, $this->article->fresh()->accepted_version_id);
        $this->assertDatabaseCount('article_accepted_file_sets', 1);
        $this->assertDatabaseHas('article_accepted_file_set_items', ['article_file_id' => $this->manuscript->id, 'accepted_role' => 'manuscript']);
        $this->withHeader('Idempotency-Key', 'accept-v1-again')->postJson("/api/admin/lifecycle/articles/{$this->article->id}/editorial-decisions", ['article_version_id' => $this->version->id, 'decision' => 'accepted', 'decision_source' => 'editor_personal_review'])->assertConflict();
    }

    public function test_publication_requires_one_approved_primary_pdf_and_supports_unpublication(): void
    {
        $this->version->update(['screening_status' => 'passed', 'accepted_at' => now(), 'accepted_by' => $this->editor->id]);
        $this->article->update(['status' => ArticleStatus::READY_FOR_PUBLICATION, 'accepted_version_id' => $this->version->id]);
        $set = app(AcceptedFileSetService::class)->createForCurrentSubmission($this->article, $this->editor);
        $proof = ProofRound::create(['article_id' => $this->article->id, 'article_version_id' => $this->version->id, 'accepted_file_set_id' => $set->id, 'round_number' => 1, 'status' => 'approved', 'source_file_id' => $this->manuscript->id, 'requested_at' => now(), 'approved_at' => now(), 'approved_by' => $this->author->id]);
        $issue = MagazineIssue::create(['magazine_id' => $this->magazine->id, 'volume_number' => 1, 'issue_number' => 1, 'issue_year' => now()->year, 'status' => 'draft']);
        Sanctum::actingAs($this->publisher);
        $recordId = $this->withHeader('Idempotency-Key', 'prepare-publication')->postJson("/api/admin/lifecycle/articles/{$this->article->id}/publication-records", ['magazine_issue_id' => $issue->id, 'doi' => '10.5555/final.1', 'page_start' => 1, 'page_end' => 10])->assertOk()->json('result.publication_record_id');
        $this->withHeader('Idempotency-Key', 'no-primary')->putJson("/api/admin/lifecycle/publication-records/{$recordId}/files", ['selections' => [['article_file_id' => $this->manuscript->id, 'public_role' => 'supplementary', 'is_public' => true]]])->assertConflict();
        $this->withHeader('Idempotency-Key', 'primary-file')->putJson("/api/admin/lifecycle/publication-records/{$recordId}/files", ['selections' => [['article_file_id' => $this->manuscript->id, 'public_role' => 'primary_manuscript', 'is_primary' => true, 'is_public' => true]]])->assertOk();
        $this->withHeader('Idempotency-Key', 'publish-record')->postJson("/api/admin/lifecycle/publication-records/{$recordId}/publish")->assertOk();
        $this->assertSame(ArticleStatus::PUBLISHED, $this->article->fresh()->status);
        $this->withHeader('Idempotency-Key', 'unpublish-record')->postJson("/api/admin/lifecycle/publication-records/{$recordId}/unpublish", ['reason' => 'Publisher correction'])->assertOk();
        $this->assertDatabaseHas('publication_records', ['id' => $recordId, 'status' => 'unpublished']);
    }

    public function test_publisher_copyediting_and_author_proof_approval_reach_publication_gate(): void
    {
        $this->version->update(['screening_status' => 'passed']);
        Sanctum::actingAs($this->editor);
        $this->withHeader('Idempotency-Key', 'accept-for-production')->postJson("/api/admin/lifecycle/articles/{$this->article->id}/editorial-decisions", [
            'article_version_id' => $this->version->id,
            'decision' => 'accepted',
            'decision_source' => 'editor_personal_review',
        ])->assertOk();

        Sanctum::actingAs($this->publisher);
        $assignmentId = $this->withHeader('Idempotency-Key', 'assign-copy-editor')->postJson("/api/admin/lifecycle/articles/{$this->article->id}/copy-editor", [
            'copy_editor_id' => $this->copyEditor->id,
        ])->assertOk()->json('result.assignment_id');

        $copyedited = ArticleFile::create([
            'article_id' => $this->article->id,
            'article_version_id' => $this->version->id,
            'uploaded_by' => $this->copyEditor->id,
            'file_type' => ArticleFile::COPY_EDITED_FILE,
            'visibility' => 'production_only',
            'assignment_type' => 'production_assignment',
            'assignment_id' => $assignmentId,
            'file_path' => 'clean/copyedited.pdf',
            'storage_key' => 'clean/copyedited.pdf',
            'original_name' => 'copyedited.pdf',
            'safe_original_name' => 'copyedited.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'scan_status' => 'clean',
        ]);

        Sanctum::actingAs($this->copyEditor);
        $this->withHeader('Idempotency-Key', 'complete-copyediting')->postJson("/api/admin/lifecycle/production-assignments/{$assignmentId}/complete-copyediting", [
            'copyedited_file_id' => $copyedited->id,
        ])->assertOk();

        Sanctum::actingAs($this->publisher);
        $proofId = $this->withHeader('Idempotency-Key', 'request-author-proof')->postJson("/api/admin/lifecycle/articles/{$this->article->id}/proof-rounds", [
            'source_file_id' => $copyedited->id,
        ])->assertOk()->json('result.proof_round_id');

        Sanctum::actingAs($this->author);
        $this->withHeader('Idempotency-Key', 'approve-author-proof')->postJson("/api/admin/lifecycle/proof-rounds/{$proofId}/author-response", [
            'decision' => 'approve',
        ])->assertOk()->assertJsonPath('status.canonical', 'ready_for_publication');

        $this->assertDatabaseHas('proof_rounds', ['id' => $proofId, 'status' => 'approved']);
        $this->assertSame(ArticleStatus::READY_FOR_PUBLICATION, $this->article->fresh()->status);
    }
}
