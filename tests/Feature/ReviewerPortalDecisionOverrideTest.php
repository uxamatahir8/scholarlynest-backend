<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleReviewRound;
use App\Models\ArticleVersion;
use App\Models\EditorialDecision;
use App\Models\Magazine;
use App\Models\Permission;
use App\Models\ReviewQuestionnaire;
use App\Models\ReviewQuestionnaireVersion;
use App\Models\ReviewerAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReviewerPortalDecisionOverrideTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;
    private User $reviewer;
    private User $otherReviewer;
    private User $author;
    private Article $article;
    private ArticleVersion $initial;
    private ArticleVersion $revision;
    private ArticleReviewRound $initialRound;
    private ArticleReviewRound $revisionRound;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $view = Permission::create(['name' => 'articles.view-own', 'module' => 'articles']);
        $approve = Permission::create(['name' => 'articles.approve', 'module' => 'articles']);
        $roles = collect(['author', 'editor', 'reviewer'])->mapWithKeys(fn ($name) => [
            $name => Role::create(['name' => $name, 'display_name' => ucfirst($name), 'is_system' => true]),
        ]);
        $roles['editor']->permissions()->sync([$view->id, $approve->id]);
        $roles['reviewer']->permissions()->sync([$view->id]);
        $roles['author']->permissions()->sync([$view->id]);
        $this->editor = User::factory()->create(['role_id' => $roles['editor']->id]);
        $this->reviewer = User::factory()->create(['role_id' => $roles['reviewer']->id]);
        $this->otherReviewer = User::factory()->create(['role_id' => $roles['reviewer']->id]);
        $this->author = User::factory()->create(['role_id' => $roles['author']->id]);
        $magazine = Magazine::create(['title' => 'Reviewer Portal Journal', 'slug' => 'reviewer-portal-journal', 'description' => 'Test']);
        $this->editor->magazines()->attach($magazine->id, ['role' => 'editor']);
        $this->article = Article::create([
            'magazine_id' => $magazine->id,
            'user_id' => $this->author->id,
            'title' => 'Assignment Scoped Reviews',
            'slug' => 'assignment-scoped-reviews',
            'abstract' => 'Abstract',
            'full_text' => '',
            'status' => ArticleStatus::REVIEW_IN_PROGRESS,
        ]);
        $this->initial = ArticleVersion::create([
            'article_id' => $this->article->id, 'created_by' => $this->author->id,
            'version_number' => 1, 'revision_number' => 0, 'status_snapshot' => ArticleStatus::UNDER_REVIEW,
            'screening_status' => 'passed', 'submitted_at' => now()->subDay(),
        ]);
        $this->revision = ArticleVersion::create([
            'article_id' => $this->article->id, 'parent_version_id' => $this->initial->id,
            'created_by' => $this->author->id, 'version_number' => 2, 'revision_number' => 1,
            'status_snapshot' => ArticleStatus::UNDER_REVIEW, 'screening_status' => 'passed', 'submitted_at' => now(),
        ]);
        $this->article->update(['current_version_id' => $this->revision->id]);
        $this->initialRound = ArticleReviewRound::create([
            'article_id' => $this->article->id, 'article_version_id' => $this->initial->id,
            'round_number' => 1, 'status' => ArticleReviewRound::CLOSED, 'opened_at' => now()->subDay(), 'closed_at' => now(),
        ]);
        $this->revisionRound = ArticleReviewRound::create([
            'article_id' => $this->article->id, 'article_version_id' => $this->revision->id,
            'round_number' => 1, 'status' => ArticleReviewRound::OPEN, 'opened_at' => now(),
        ]);
        $questionnaire = ReviewQuestionnaire::create(['name' => 'Reviewer Form', 'is_active' => true, 'created_by' => $this->editor->id]);
        ReviewQuestionnaireVersion::create([
            'review_questionnaire_id' => $questionnaire->id, 'version_number' => 1,
            'is_active' => true, 'published_at' => now(),
        ]);
    }

    public function test_dashboard_groups_invites_and_accept_decline_are_idempotent_and_assignment_scoped(): void
    {
        $initialInvite = $this->assignment($this->initial, $this->initialRound, 'invited');
        $revisionInvite = $this->assignment($this->revision, $this->revisionRound, 'invited');

        Sanctum::actingAs($this->reviewer);
        $this->getJson('/api/admin/my-reviewer-assignments')
            ->assertOk()
            ->assertJsonCount(2, 'pending_invitations')
            ->assertJsonPath('pending_invitations.0.capabilities.accept_invitation', true)
            ->assertJsonPath('pending_invitations.0.capabilities.decline_invitation', true);

        $accept = ['decision' => 'accept'];
        $this->withHeader('Idempotency-Key', 'accept-initial')->postJson("/api/admin/lifecycle/reviewer-assignments/{$initialInvite->id}/response", $accept)
            ->assertOk()->assertJsonPath('result.status', 'accepted');
        $this->withHeader('Idempotency-Key', 'accept-initial')->postJson("/api/admin/lifecycle/reviewer-assignments/{$initialInvite->id}/response", $accept)
            ->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->assertNotNull($initialInvite->fresh()->questionnaire_instance_id);
        $this->assertSame('invited', $revisionInvite->fresh()->status);
        $this->getJson("/api/admin/my-reviewer-assignments/{$initialInvite->id}")
            ->assertOk()
            ->assertJsonPath('data.version_label', 'Initial Submission')
            ->assertJsonPath('data.capabilities.start_review', true);
        $this->withHeader('Idempotency-Key', 'start-initial')->postJson("/api/admin/lifecycle/reviewer-assignments/{$initialInvite->id}/start")
            ->assertOk()->assertJsonPath('result.status', 'in_progress');
        $this->withHeader('Idempotency-Key', 'draft-initial')->putJson("/api/admin/lifecycle/reviewer-assignments/{$initialInvite->id}/draft", [
            'recommendation' => 'minor_revision',
            'author_comments' => 'Draft comments.',
            'questionnaire_responses' => [],
        ])->assertOk()->assertJsonPath('result.status', 'in_progress');
        $this->assertNotNull($initialInvite->fresh()->started_at);
        $this->assertDatabaseHas('article_audit_logs', ['article_id' => $this->article->id, 'event' => 'review.draft_saved']);
        $this->assertDatabaseHas('notification_events', ['article_id' => $this->article->id, 'event_type' => 'review.draft_saved']);

        $this->withHeader('Idempotency-Key', 'decline-revision')->postJson("/api/admin/lifecycle/reviewer-assignments/{$revisionInvite->id}/response", [
            'decision' => 'decline', 'reason' => 'Outside my expertise.',
        ])->assertOk()->assertJsonPath('result.status', 'declined');
        $this->withHeader('Idempotency-Key', 'decline-revision')->postJson("/api/admin/lifecycle/reviewer-assignments/{$revisionInvite->id}/response", [
            'decision' => 'decline', 'reason' => 'Outside my expertise.',
        ])->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame('in_progress', $initialInvite->fresh()->status);
        $this->assertDatabaseHas('reviewer_assignments', ['id' => $revisionInvite->id, 'status' => 'declined', 'decline_reason' => 'Outside my expertise.']);
    }

    public function test_expired_or_foreign_invitation_cannot_be_accepted(): void
    {
        $expired = $this->assignment($this->revision, $this->revisionRound, 'invited', ['invite_expires_at' => now()->subMinute()]);

        Sanctum::actingAs($this->reviewer);
        $this->withHeader('Idempotency-Key', 'expired-accept')->postJson("/api/admin/lifecycle/reviewer-assignments/{$expired->id}/response", ['decision' => 'accept'])
            ->assertConflict();

        Sanctum::actingAs($this->otherReviewer);
        $this->withHeader('Idempotency-Key', 'foreign-accept')->postJson("/api/admin/lifecycle/reviewer-assignments/{$expired->id}/response", ['decision' => 'accept'])
            ->assertForbidden();
        $this->assertSame('invited', $expired->fresh()->status);
    }

    public function test_editorial_decision_requires_policy_and_keep_open_allows_late_exact_version_review(): void
    {
        $pending = $this->assignment($this->revision, $this->revisionRound, 'accepted', ['accepted_at' => now()]);
        $historical = $this->assignment($this->initial, $this->initialRound, 'accepted', ['accepted_at' => now()]);
        Sanctum::actingAs($this->editor);
        $payload = [
            'article_version_id' => $this->revision->id,
            'decision' => 'major_revision',
            'decision_source' => 'mixed_editorial_decision',
            'author_comments' => 'Revise the manuscript.',
        ];
        $this->withHeader('Idempotency-Key', 'decision-r1')->postJson("/api/admin/lifecycle/articles/{$this->article->id}/editorial-decisions", $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'PENDING_REVIEWS_REQUIRE_CONFIRMATION')
            ->assertJsonPath('pending_review_count', 1)
            ->assertJsonPath('pending_reviews.0.assignment_id', $pending->id);
        $this->assertDatabaseCount('editorial_decisions', 0);

        $confirmed = $payload + [
            'pending_review_policy' => 'keep_open',
            'pending_review_override_reason' => 'Sufficient reviews were received.',
        ];
        $this->withHeader('Idempotency-Key', 'decision-r1')->postJson("/api/admin/lifecycle/articles/{$this->article->id}/editorial-decisions", $confirmed)
            ->assertOk();
        $this->assertSame('accepted', $pending->fresh()->status);
        $this->assertSame('accepted', $historical->fresh()->status);
        $this->assertDatabaseHas('editorial_decisions', [
            'article_version_id' => $this->revision->id,
            'pending_review_policy' => 'keep_open',
            'pending_review_count' => 1,
        ]);

        Sanctum::actingAs($this->reviewer);
        $this->withHeader('Idempotency-Key', 'late-r1-review')->postJson("/api/admin/lifecycle/reviewer-assignments/{$pending->id}/review", [
            'recommendation' => 'major_revision',
            'author_comments' => 'Historical review comments.',
        ])->assertOk();
        $this->assertDatabaseHas('reviewer_assignments', [
            'id' => $pending->id, 'status' => 'completed', 'submitted_after_decision' => true,
        ]);
        $this->assertSame(ArticleStatus::MAJOR_REVISION_REQUIRED, $this->article->fresh()->status);
        $this->assertSame(1, EditorialDecision::where('article_version_id', $this->revision->id)->count());
    }

    public function test_close_pending_closes_only_selected_version_and_revokes_questionnaire_access(): void
    {
        $pending = $this->assignment($this->revision, $this->revisionRound, 'accepted', ['accepted_at' => now()]);
        app(\App\Services\ReviewerQuestionnaireService::class)->ensure($pending);
        $historical = $this->assignment($this->initial, $this->initialRound, 'accepted', ['accepted_at' => now()]);
        Sanctum::actingAs($this->editor);
        $this->withHeader('Idempotency-Key', 'close-pending-r1')->postJson("/api/admin/lifecycle/articles/{$this->article->id}/editorial-decisions", [
            'article_version_id' => $this->revision->id,
            'decision' => 'rejected',
            'decision_source' => 'mixed_editorial_decision',
            'pending_review_policy' => 'close_pending',
            'pending_review_override_reason' => 'The editorial record is sufficient.',
        ])->assertOk();

        $pending->refresh();
        $this->assertSame('closed_without_review', $pending->status);
        $this->assertNotNull($pending->closed_at);
        $this->assertFalse(app(\App\Services\ReviewerQuestionnaireService::class)->canAccess($pending));
        $this->assertSame('accepted', $historical->fresh()->status);
        $this->assertDatabaseHas('article_audit_logs', ['article_id' => $this->article->id, 'event' => 'review.closed_without_review']);
        $this->assertDatabaseHas('notification_events', ['article_id' => $this->article->id, 'event_type' => 'review.closed_without_review']);
    }

    public function test_author_can_open_completed_review_comments_during_active_review_without_reviewer_identity(): void
    {
        $completed = $this->assignment($this->revision, $this->revisionRound, 'completed', [
            'completed_at' => now(),
            'recommendation' => 'minor_revision',
            'comments_for_author' => 'Please clarify the statistical method.',
            'confidential_comments' => 'Reviewer identity must remain confidential.',
        ]);

        Sanctum::actingAs($this->author);
        $response = $this->getJson("/api/admin/articles/{$this->article->id}/workflow")->assertOk();
        $review = collect($response->json('article.reviewer_assignments'))->firstWhere('id', $completed->id);

        $this->assertNotNull($review);
        $this->assertSame($this->revision->id, $review['article_version_id']);
        $this->assertSame('Please clarify the statistical method.', $review['comments_for_author']);
        $this->assertArrayNotHasKey('reviewer_id', $review);
        $this->assertArrayNotHasKey('reviewer', $review);
        $this->assertArrayNotHasKey('invitee_name', $review);
        $this->assertArrayNotHasKey('invitee_email', $review);
        $this->assertArrayNotHasKey('confidential_comments', $review);

        $revisionTab = collect($response->json('workflow_manifest.tabs'))->firstWhere('version_id', $this->revision->id);
        $this->assertContains($completed->id, collect($revisionTab['sidebar'])->pluck('review_id'));
        $this->assertContains('Reviewer 1 Review', collect($revisionTab['sidebar'])->pluck('label'));
    }

    private function assignment(ArticleVersion $version, ArticleReviewRound $round, string $status, array $extra = []): ReviewerAssignment
    {
        return ReviewerAssignment::create(array_merge([
            'article_id' => $this->article->id,
            'article_version_id' => $version->id,
            'review_round_id' => $round->id,
            'round_number' => 1,
            'reviewer_id' => $this->reviewer->id,
            'invitee_name' => $this->reviewer->name,
            'invitee_email' => $this->reviewer->email,
            'assigned_by' => $this->editor->id,
            'status' => $status,
            'invited_at' => now(),
            'invite_expires_at' => now()->addWeek(),
            'due_date' => now()->addDays(10),
        ], $extra));
    }
}
