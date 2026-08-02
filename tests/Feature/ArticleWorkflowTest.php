<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleAuthor;
use App\Models\ArticleFile;
use App\Models\ArticleReviewerPreference;
use App\Models\ArticleReviewRound;
use App\Models\ArticleVersion;
use App\Models\Magazine;
use App\Models\MagazineIssue;
use App\Models\Permission;
use App\Models\ProductionAssignment;
use App\Models\ReviewerAssignment;
use App\Models\Role;
use App\Models\User;
use App\Services\AcceptedFileSetService;
use App\Services\ArticleReviewRoundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArticleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    private User $subEditor;

    private User $reviewer;

    private User $author;

    private Magazine $magazine;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRole = Role::create(['name' => 'super_admin', 'display_name' => 'Super Admin', 'is_system' => true]);
        $editorRole = Role::create(['name' => 'editor', 'display_name' => 'Editor', 'is_system' => true]);
        $subEditorRole = Role::create(['name' => 'sub_editor', 'display_name' => 'Sub Editor', 'is_system' => true]);
        $reviewerRole = Role::create(['name' => 'reviewer', 'display_name' => 'Reviewer', 'is_system' => true]);
        $authorRole = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);

        foreach (['articles.view-own', 'articles.edit-own', 'articles.approve'] as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission],
                ['module' => 'articles', 'description' => $permission]
            );
        }

        $editorRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.approve'])->pluck('id'));
        $subEditorRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own'])->pluck('id'));
        $reviewerRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.edit-own'])->pluck('id'));
        $authorRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.edit-own'])->pluck('id'));

        $this->admin = User::factory()->create(['role_id' => $superAdminRole->id]);
        $this->editor = User::factory()->create(['role_id' => $editorRole->id]);
        $this->subEditor = User::factory()->create(['role_id' => $subEditorRole->id]);
        $this->reviewer = User::factory()->create(['role_id' => $reviewerRole->id]);
        $this->author = User::factory()->create(['role_id' => $authorRole->id]);

        $this->magazine = Magazine::create([
            'title' => 'Workflow Magazine',
            'slug' => 'workflow-magazine',
            'description' => 'Workflow test magazine',
        ]);

        $this->editor->magazines()->attach($this->magazine->id, ['role' => 'editor']);
        $this->subEditor->magazines()->attach($this->magazine->id, ['role' => 'sub_editor']);
        $this->reviewer->magazines()->attach($this->magazine->id, ['role' => 'reviewer']);

        // Link the sub editor to the editor
        $this->editor->assignedSubEditors()->attach($this->subEditor->id);

        $this->article = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->author->id,
            'title' => 'Workflow Article',
            'slug' => 'workflow-article',
            'abstract' => 'Abstract',
            'full_text' => 'Full text',
            'status' => ArticleStatus::SUBMITTED,
        ]);

        $version = ArticleVersion::create([
            'article_id' => $this->article->id,
            'version_number' => 1,
            'created_by' => $this->author->id,
            'status_snapshot' => ArticleStatus::SUBMITTED,
            'submitted_at' => now(),
            'screening_status' => 'passed',
            'screened_at' => now(),
        ]);
        $this->article->update(['current_version_id' => $version->id]);
    }

    public function test_editor_can_assign_sub_editor_and_reviewer(): void
    {
        Sanctum::actingAs($this->editor);

        $this->postJson("/api/admin/articles/{$this->article->id}/assign-sub-editor", [
            'sub_editor_id' => $this->subEditor->id,
        ])->assertStatus(201)
            ->assertJsonPath('article.status', ArticleStatus::ASSIGNED_TO_SUB_EDITOR);

        $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'reviewer_id' => $this->reviewer->id,
        ])->assertStatus(201)
            ->assertJsonPath('article.status', ArticleStatus::REVIEWER_ASSIGNED);

        $this->assertDatabaseHas('article_audit_logs', [
            'article_id' => $this->article->id,
            'event' => 'reviewer.assigned',
        ]);
    }

    public function test_every_submitted_revision_has_an_independent_open_reviewer_round_and_history(): void
    {
        $initial = $this->article->currentVersion;
        $initialRound = app(ArticleReviewRoundService::class)->ensureForSubmittedVersion($this->article->fresh(), $initial, $this->editor);
        $historical = ReviewerAssignment::create([
            'article_id' => $this->article->id,
            'article_version_id' => $initial->id,
            'review_round_id' => $initialRound->id,
            'round_number' => 1,
            'reviewer_id' => $this->reviewer->id,
            'invitee_name' => $this->reviewer->name,
            'invitee_email' => $this->reviewer->email,
            'assigned_by' => $this->editor->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $parent = $initial;
        $roundIds = [$initialRound->id];

        Sanctum::actingAs($this->editor);
        foreach (range(1, 3) as $revisionNumber) {
            $version = ArticleVersion::create([
                'article_id' => $this->article->id,
                'parent_version_id' => $parent->id,
                'version_number' => $revisionNumber + 1,
                'revision_number' => $revisionNumber,
                'label' => 'Revised Manuscript',
                'created_by' => $this->author->id,
                'status_snapshot' => ArticleStatus::RESUBMITTED,
                'screening_status' => 'pending',
                'submitted_at' => now()->addMinutes($revisionNumber),
            ]);
            $this->article->update(['current_version_id' => $version->id, 'status' => ArticleStatus::RESUBMITTED]);

            $reviewers = $this->getJson("/api/admin/articles/{$this->article->id}/versions/{$version->id}/reviewers")
                ->assertOk()
                ->assertJsonPath('data.version_id', $version->id)
                ->assertJsonPath('data.reviewers.status', ArticleReviewRound::OPEN)
                ->assertJsonPath('data.capabilities.invite', true)
                ->assertJsonPath('data.capabilities.manual_invitation', true)
                ->assertJsonPath('data.reviewer_preferences.suggested.0.previous_review.label', 'Initial Submission');
            $roundId = $reviewers->json('data.reviewers.review_round_id');
            $roundIds[] = $roundId;

            $assignmentId = $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
                'reviewer_id' => $this->reviewer->id,
                'article_version_id' => $version->id,
                'review_round_id' => $roundId,
                'round_number' => 1,
                'idempotency_key' => "revision-{$revisionNumber}-reviewer-{$this->reviewer->id}",
            ])->assertCreated()
                ->assertJsonPath('assignment.article_version_id', $version->id)
                ->assertJsonPath('assignment.review_round_id', $roundId)
                ->json('assignment.id');

            $this->assertNotSame($historical->id, $assignmentId);
            $this->assertSame('completed', $historical->fresh()->status);
            $parent = $version;
        }

        $this->assertCount(4, array_unique($roundIds));
        $this->assertDatabaseCount('reviewer_assignments', 4);

        $this->getJson("/api/admin/articles/{$this->article->id}/versions/{$initial->id}/reviewers")
            ->assertOk()
            ->assertJsonPath('data.capabilities.manual_invitation', false)
            ->assertJsonPath('data.disabled_reason.code', 'VERSION_NOT_CURRENT');
    }

    public function test_submitted_revision_cannot_repeat_editorial_screening(): void
    {
        $initial = $this->article->currentVersion;
        $revision = ArticleVersion::create([
            'article_id' => $this->article->id,
            'parent_version_id' => $initial->id,
            'version_number' => 2,
            'revision_number' => 1,
            'label' => 'Revised Manuscript',
            'created_by' => $this->author->id,
            'status_snapshot' => ArticleStatus::SUBMITTED,
            'screening_status' => 'pending',
            'submitted_at' => now(),
        ]);
        $this->article->update([
            'current_version_id' => $revision->id,
            'status' => ArticleStatus::SUBMITTED,
        ]);

        Sanctum::actingAs($this->editor);
        $this->postJson("/api/admin/articles/{$this->article->id}/screen", [
            'decision' => 'send_to_review',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Editorial screening is only performed for the initial submission.');

        $this->assertSame('pending', $revision->fresh()->screening_status);
        $this->assertSame(ArticleStatus::SUBMITTED, $this->article->fresh()->status);
    }

    public function test_assigned_sub_editor_can_invite_suggested_and_manual_reviewers_without_approval_permission(): void
    {
        $suggestedReviewer = ArticleReviewerPreference::create([
            'article_id' => $this->article->id,
            'created_by_author_id' => $this->author->id,
            'type' => ArticleReviewerPreference::SUGGESTED,
            'name' => 'Suggested Reviewer',
            'email' => 'suggested.reviewer@example.test',
            'affiliation' => 'Suggested University',
        ]);

        Sanctum::actingAs($this->editor);
        $this->postJson("/api/admin/articles/{$this->article->id}/assign-sub-editor", [
            'sub_editor_id' => $this->subEditor->id,
        ])->assertCreated();

        Sanctum::actingAs($this->subEditor);
        $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'suggested_preference_id' => $suggestedReviewer->id,
        ])->assertCreated();

        $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'name' => 'Manual Reviewer',
            'email' => 'manual.reviewer@example.test',
            'affiliation' => 'Manual University',
        ])->assertCreated();

        $this->assertDatabaseHas('reviewer_assignments', [
            'article_id' => $this->article->id,
            'invitee_email' => 'suggested.reviewer@example.test',
            'assigned_by' => $this->subEditor->id,
        ]);
        $this->assertDatabaseHas('reviewer_assignments', [
            'article_id' => $this->article->id,
            'invitee_email' => 'manual.reviewer@example.test',
            'assigned_by' => $this->subEditor->id,
        ]);
    }

    public function test_article_registry_supports_column_filters_and_scoped_author_options(): void
    {
        $issue = MagazineIssue::create([
            'magazine_id' => $this->magazine->id,
            'volume_number' => 12,
            'issue_number' => 3,
            'special_title' => 'Clinical Engineering',
        ]);
        $this->article->update(['magazine_issue_id' => $issue->id, 'title' => 'Advanced Biomedical Methods']);

        Sanctum::actingAs($this->editor);
        $this->getJson('/api/admin/articles?tracking_code='.urlencode($this->article->tracking_code))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tracking_code', $this->article->tracking_code);
        $this->getJson('/api/admin/articles?tracking_code='.urlencode(substr($this->article->tracking_code, 0, -1)))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/articles?title=Biomedical')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/articles?issue=Clinical')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/articles?search='.urlencode($this->article->tracking_code))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/articles?search=Biomedical')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/articles?search=Clinical')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/admin/articles?author_id={$this->author->id}")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/articles/filter-options')
            ->assertOk()
            ->assertJsonFragment(['id' => $this->author->id, 'name' => $this->author->name]);
    }

    public function test_only_publisher_or_global_admin_can_assign_production(): void
    {
        $publisherRole = Role::create(['name' => 'publisher', 'display_name' => 'Publisher', 'is_system' => true]);
        $copyEditorRole = Role::create(['name' => 'copy_editor', 'display_name' => 'Copy Editor', 'is_system' => true]);
        $publisherRole->permissions()->sync(Permission::where('name', 'articles.approve')->pluck('id'));
        $publisher = User::factory()->create(['role_id' => $publisherRole->id]);
        $copyEditor = User::factory()->create(['role_id' => $copyEditorRole->id]);
        $publisher->magazines()->attach($this->magazine->id, ['role' => 'publisher']);
        $this->article->update(['status' => ArticleStatus::ACCEPTED]);
        $version = $this->article->currentVersion;
        ArticleFile::create([
            'article_id' => $this->article->id,
            'article_version_id' => $version->id,
            'uploaded_by' => $this->author->id,
            'file_type' => ArticleFile::MANUSCRIPT,
            'visibility' => 'author_visible',
            'file_path' => 'clean/production-source.pdf',
            'original_name' => 'production-source.pdf',
            'mime_type' => 'application/pdf',
            'size' => 14,
            'scan_status' => 'clean',
        ]);
        app(AcceptedFileSetService::class)->createForCurrentSubmission($this->article, $this->editor);

        Sanctum::actingAs($this->editor);
        $this->postJson("/api/admin/articles/{$this->article->id}/production-assignments", [
            'user_id' => $copyEditor->id,
            'role' => 'copy_editor',
        ])->assertForbidden();

        Sanctum::actingAs($publisher);
        $this->postJson("/api/admin/articles/{$this->article->id}/production-assignments", [
            'user_id' => $copyEditor->id,
            'role' => 'copy_editor',
        ])->assertCreated();
    }

    public function test_reviewer_can_submit_review_and_editor_can_record_final_decision(): void
    {
        Sanctum::actingAs($this->editor);
        $assignmentId = $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'reviewer_id' => $this->reviewer->id,
        ])->json('assignment.id');

        Sanctum::actingAs($this->reviewer);
        $this->postJson("/api/admin/reviewer-assignments/{$assignmentId}/accept")
            ->assertOk();
        $this->postJson("/api/admin/reviewer-assignments/{$assignmentId}/submit-review", [
            'recommendation' => 'accept',
            'comments_for_author' => 'Strong paper.',
        ])->assertStatus(200);

        $version = $this->article->currentVersion;
        ArticleFile::create([
            'article_id' => $this->article->id,
            'article_version_id' => $version->id,
            'uploaded_by' => $this->author->id,
            'file_type' => ArticleFile::MANUSCRIPT,
            'visibility' => 'author_visible',
            'file_path' => 'clean/workflow-manuscript.pdf',
            'original_name' => 'workflow-manuscript.pdf',
            'mime_type' => 'application/pdf',
            'size' => 14,
            'scan_status' => 'clean',
        ]);

        Sanctum::actingAs($this->editor);
        $this->postJson("/api/admin/articles/{$this->article->id}/final-decision", [
            'decision' => 'accepted',
            'decision_source' => 'reviewer_recommendation',
            'comments_for_author' => 'Accepted.',
        ])->assertStatus(201)
            ->assertJsonPath('article.status', ArticleStatus::ACCEPTED);
    }

    public function test_author_final_review_moves_proofreading_article_to_ready_for_publication_once(): void
    {
        $this->article->update(['status' => ArticleStatus::PROOFREADING]);

        Sanctum::actingAs($this->author);
        $this->postJson("/api/admin/articles/{$this->article->id}/author-final-review")
            ->assertOk()
            ->assertJsonPath('article.status', ArticleStatus::READY_FOR_PUBLICATION)
            ->assertJsonPath('article.can_author_final_review', false);

        $this->assertDatabaseHas('articles', [
            'id' => $this->article->id,
            'status' => ArticleStatus::READY_FOR_PUBLICATION,
            'author_final_approved_by' => $this->author->id,
        ]);
        $this->assertNotNull($this->article->fresh()->author_final_approved_at);

        $this->postJson("/api/admin/articles/{$this->article->id}/author-final-review")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Author publication review is available only after copyediting is completed.');
    }

    public function test_author_final_review_blocks_wrong_state_wrong_user_and_duplicates(): void
    {
        $otherAuthor = User::factory()->create(['role_id' => $this->author->role_id]);
        ArticleAuthor::create([
            'article_id' => $this->article->id,
            'user_id' => $otherAuthor->id,
            'co_author_name' => $otherAuthor->name,
            'co_author_email' => $otherAuthor->email,
            'author_order' => 2,
            'is_owner' => false,
            'is_corresponding' => false,
            'can_edit' => false,
        ]);

        Sanctum::actingAs($this->author);
        $this->postJson("/api/admin/articles/{$this->article->id}/author-final-review")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Author publication review is available only after copyediting is completed.');

        $this->article->update(['status' => ArticleStatus::PROOFREADING]);

        Sanctum::actingAs($otherAuthor);
        $this->postJson("/api/admin/articles/{$this->article->id}/author-final-review")
            ->assertForbidden();

        Sanctum::actingAs($this->author);
        $this->postJson("/api/admin/articles/{$this->article->id}/author-final-review")
            ->assertOk();
        Article::whereKey($this->article->id)->update(['status' => ArticleStatus::PROOFREADING]);
        $this->postJson("/api/admin/articles/{$this->article->id}/author-final-review")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This manuscript has already been approved for final review.');
    }

    public function test_author_can_deny_publication_with_reason_and_reopen_copyediting(): void
    {
        $assignment = ProductionAssignment::create([
            'article_id' => $this->article->id,
            'user_id' => $this->editor->id,
            'role' => 'copy_editor',
            'assigned_by' => $this->admin->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $this->article->update([
            'status' => ArticleStatus::PROOFREADING,
            'author_final_review_requested_at' => now(),
            'author_final_review_due_at' => now()->addDays(14),
        ]);

        Sanctum::actingAs($this->author);
        $this->postJson("/api/admin/articles/{$this->article->id}/author-final-review", ['decision' => 'denied'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/admin/articles/{$this->article->id}/author-final-review", [
            'decision' => 'denied',
            'reason' => 'Please correct the author affiliation in the copyedited file.',
        ])->assertOk()
            ->assertJsonPath('article.status', ArticleStatus::COPY_EDITING);

        $this->assertDatabaseHas('production_assignments', [
            'id' => $assignment->id,
            'status' => 'pending',
            'completed_at' => null,
        ]);
        $this->assertDatabaseHas('articles', [
            'id' => $this->article->id,
            'status' => ArticleStatus::COPY_EDITING,
            'author_final_rejection_reason' => 'Please correct the author affiliation in the copyedited file.',
            'author_final_review_due_at' => null,
        ]);
    }

    public function test_expired_author_review_is_automatically_approved_but_future_review_is_not(): void
    {
        $this->article->update([
            'status' => ArticleStatus::PROOFREADING,
            'author_final_review_requested_at' => now()->subDays(14)->subMinute(),
            'author_final_review_due_at' => now()->subMinute(),
        ]);
        $future = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->author->id,
            'title' => 'Future Author Review',
            'slug' => 'future-author-review',
            'abstract' => 'Abstract',
            'full_text' => 'Text',
            'status' => ArticleStatus::PROOFREADING,
            'author_final_review_requested_at' => now(),
            'author_final_review_due_at' => now()->addMinute(),
        ]);

        $this->artisan('workflow:auto-approve-author-final-reviews')
            ->expectsOutput('Author final reviews automatically approved: 1')
            ->assertSuccessful();

        $approved = $this->article->fresh();
        $this->assertSame(ArticleStatus::READY_FOR_PUBLICATION, $approved->status);
        $this->assertNotNull($approved->author_final_approved_at);
        $this->assertNotNull($approved->author_final_auto_approved_at);
        $this->assertNull($approved->author_final_approved_by);
        $this->assertSame(ArticleStatus::PROOFREADING, $future->fresh()->status);
        $this->assertDatabaseHas('article_audit_logs', [
            'article_id' => $this->article->id,
            'event' => 'author.final_review_auto_approved',
        ]);
    }

    public function test_sub_editor_recommendation_and_reviewer_acceptance(): void
    {
        Sanctum::actingAs($this->editor);
        $subEditorAssignmentId = $this->postJson("/api/admin/articles/{$this->article->id}/assign-sub-editor", [
            'sub_editor_id' => $this->subEditor->id,
        ])->json('assignment.id');

        $reviewerAssignmentId = $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'reviewer_id' => $this->reviewer->id,
        ])->json('assignment.id');

        Sanctum::actingAs($this->subEditor);
        $this->postJson("/api/admin/sub-editor-assignments/{$subEditorAssignmentId}/submit-recommendation", [
            'recommendation' => 'minor_revision',
            'comments' => 'Needs a clearer methods section.',
            'internal_notes' => 'Useful but needs polish.',
        ])->assertStatus(200)
            ->assertJsonPath('assignment.status', 'completed');

        Sanctum::actingAs($this->reviewer);
        $this->postJson("/api/admin/reviewer-assignments/{$reviewerAssignmentId}/accept")
            ->assertStatus(200)
            ->assertJsonPath('assignment.status', 'accepted');
    }

    public function test_reviewer_invitation_state_is_exposed_and_deduplicated(): void
    {
        Sanctum::actingAs($this->editor);
        $assignmentId = $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'reviewer_id' => $this->reviewer->id,
        ])->assertCreated()
            ->assertJsonPath('assignment.invitation_state', 'invited')
            ->json('assignment.id');

        $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'reviewer_id' => $this->reviewer->id,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'This reviewer has already been invited or assigned for this article.');

        $this->getJson("/api/admin/articles/{$this->article->id}/workflow")
            ->assertOk()
            ->assertJsonPath('article.reviewer_assignments.0.invitation_state', 'invited');

        Sanctum::actingAs($this->reviewer);
        $this->postJson("/api/admin/reviewer-assignments/{$assignmentId}/accept")
            ->assertOk()
            ->assertJsonPath('assignment.invitation_state', 'accepted');

        Sanctum::actingAs($this->editor);
        $this->getJson("/api/admin/articles/{$this->article->id}/workflow")
            ->assertOk()
            ->assertJsonPath('article.reviewer_assignments.0.invitation_state', 'accepted');
    }

    public function test_declined_reviewer_can_be_invited_again(): void
    {
        Sanctum::actingAs($this->editor);
        $assignmentId = $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'reviewer_id' => $this->reviewer->id,
        ])->assertCreated()->json('assignment.id');

        $assignment = ReviewerAssignment::findOrFail($assignmentId);
        $originalTokenHash = $assignment->invite_token_hash;
        $assignment->update([
            'status' => 'declined',
            'declined_at' => now(),
            'invite_token_hash' => null,
        ]);

        $newAssignmentId = $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'reviewer_id' => $this->reviewer->id,
        ])->assertCreated()
            ->assertJsonPath('assignment.invitation_state', 'invited')
            ->json('assignment.id');

        $this->assertNotSame($assignmentId, $newAssignmentId);
        $this->assertDatabaseCount('reviewer_assignments', 2);
        $this->assertDatabaseHas('reviewer_assignments', [
            'id' => $assignmentId,
            'status' => 'declined',
        ]);
        $this->assertDatabaseHas('reviewer_assignments', [
            'id' => $newAssignmentId,
            'status' => 'pending',
            'declined_at' => null,
        ]);
        $this->assertNotSame($originalTokenHash, ReviewerAssignment::findOrFail($newAssignmentId)->invite_token_hash);
    }

    public function test_workflow_context_hides_confidential_notes_and_audit_logs_from_author(): void
    {
        Sanctum::actingAs($this->editor);
        $assignmentId = $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'reviewer_id' => $this->reviewer->id,
        ])->json('assignment.id');

        Sanctum::actingAs($this->reviewer);
        $this->postJson("/api/admin/reviewer-assignments/{$assignmentId}/accept")
            ->assertOk();
        $this->postJson("/api/admin/reviewer-assignments/{$assignmentId}/submit-review", [
            'recommendation' => 'minor_revision',
            'comments_for_author' => 'Please clarify the methods.',
            'confidential_comments' => 'Confidential editor-only concern.',
        ])->assertStatus(200);

        Sanctum::actingAs($this->author);
        $this->getJson("/api/admin/articles/{$this->article->id}/workflow")
            ->assertOk()
            ->assertJsonPath('article.audit_logs', [])
            ->assertJsonMissing(['confidential_comments' => 'Confidential editor-only concern.']);

        Sanctum::actingAs($this->editor);
        $this->getJson("/api/admin/articles/{$this->article->id}/workflow")
            ->assertOk()
            ->assertJsonFragment(['confidential_comments' => 'Confidential editor-only concern.'])
            ->assertJsonPath('article.audit_logs.0.article_id', $this->article->id);
    }

    public function test_publisher_issue_creation_and_article_publication(): void
    {
        Sanctum::actingAs($this->admin);

        $issueId = $this->postJson('/api/admin/issues', [
            'magazine_id' => $this->magazine->id,
            'volume_number' => 1,
            'issue_number' => 1,
            'special_title' => 'Launch Issue',
        ])->assertStatus(201)
            ->json('issue.id');

        $this->article->update(['status' => ArticleStatus::READY_FOR_PUBLICATION]);

        $this->postJson("/api/admin/articles/{$this->article->id}/publish", [
            'magazine_issue_id' => $issueId,
            'published_year' => 2026,
            'published_month' => 'June',
            'page_start' => 1,
            'page_end' => 12,
        ])->assertStatus(200)
            ->assertJsonPath('article.status', ArticleStatus::PUBLISHED);
    }

    public function test_editor_can_send_reminder_to_reviewer(): void
    {
        Sanctum::actingAs($this->editor);
        $assignmentId = $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'reviewer_id' => $this->reviewer->id,
        ])->json('assignment.id');

        $assignmentBefore = ReviewerAssignment::findOrFail($assignmentId);
        $oldHash = $assignmentBefore->invite_token_hash;

        $response = $this->postJson("/api/admin/reviewer-assignments/{$assignmentId}/remind");
        $response->assertStatus(200);

        $assignmentAfter = ReviewerAssignment::findOrFail($assignmentId);
        $this->assertNotEmpty($assignmentAfter->invite_token_hash);
        $this->assertNotEquals($oldHash, $assignmentAfter->invite_token_hash);
    }
}
