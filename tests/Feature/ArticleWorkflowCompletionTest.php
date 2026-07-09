<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleAsset;
use App\Models\ArticlePublicationSection;
use App\Models\ArticleReviewerPreference;
use App\Models\Magazine;
use App\Models\Permission;
use App\Models\ReviewerAssignment;
use App\Models\ReviewQuestion;
use App\Models\ReviewQuestionnaireVersion;
use App\Models\ReviewQuestionResponse;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArticleWorkflowCompletionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $editor;
    private User $author;
    private Magazine $magazine;
    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create(['name' => 'super_admin', 'display_name' => 'Super Admin', 'is_system' => true]);
        $editorRole = Role::create(['name' => 'editor', 'display_name' => 'Editor', 'is_system' => true]);
        $authorRole = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);
        $reviewerRole = Role::create(['name' => 'reviewer', 'display_name' => 'Reviewer', 'is_system' => true]);

        foreach (['articles.view-own', 'articles.create', 'articles.edit-own', 'articles.approve', 'articles.manage-assets'] as $permission) {
            Permission::firstOrCreate(['name' => $permission], ['module' => 'articles', 'description' => $permission]);
        }

        $adminRole->permissions()->sync(Permission::pluck('id'));
        $editorRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.approve'])->pluck('id'));
        $authorRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own', 'articles.create', 'articles.edit-own'])->pluck('id'));
        $reviewerRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own'])->pluck('id'));

        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'email_verified_at' => now()]);
        $this->editor = User::factory()->create(['role_id' => $editorRole->id, 'email_verified_at' => now()]);
        $this->author = User::factory()->create(['role_id' => $authorRole->id, 'email_verified_at' => now()]);

        $this->magazine = Magazine::create([
            'title' => 'Workflow Completion Magazine',
            'slug' => 'workflow-completion-magazine',
            'description' => 'Workflow completion tests.',
        ]);
        $this->editor->magazines()->attach($this->magazine->id, ['role' => 'editor']);

        $this->article = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->author->id,
            'title' => 'Workflow Completion Article',
            'slug' => 'workflow-completion-article',
            'abstract' => 'Abstract',
            'full_text' => 'Legacy full text fallback',
            'status' => ArticleStatus::UNDER_REVIEW,
        ]);
    }

    public function test_questionnaire_settings_are_super_admin_only(): void
    {
        Sanctum::actingAs($this->author);
        $this->getJson('/api/admin/review-questionnaire')->assertForbidden();
        $this->postJson('/api/admin/review-questionnaire', [
            'name' => 'Blocked Form',
            'questions' => [[
                'prompt' => 'Blocked question',
                'response_type' => 'textarea',
                'is_required' => true,
            ]],
        ])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/admin/review-questionnaire')->assertOk();
    }

    public function test_opposed_reviewer_cannot_be_assigned_or_manually_invited(): void
    {
        ArticleReviewerPreference::create([
            'article_id' => $this->article->id,
            'created_by_author_id' => $this->author->id,
            'type' => ArticleReviewerPreference::OPPOSED,
            'name' => 'Blocked Reviewer',
            'email' => 'blocked@example.test',
        ]);

        Sanctum::actingAs($this->editor);

        $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'name' => 'Blocked Reviewer',
            'email' => 'blocked@example.test',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'This reviewer is listed as an opposing reviewer and cannot be assigned.');

        $this->postJson("/api/admin/articles/{$this->article->id}/assign-reviewer", [
            'name' => $this->author->name,
            'email' => $this->author->email,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Article authors and co-authors cannot be assigned as reviewers.');
    }

    public function test_external_invitation_accepts_creates_account_and_decline_does_not(): void
    {
        $acceptToken = 'accept-token';
        $acceptAssignment = $this->pendingInvitation('external.accept@example.test', $acceptToken);

        $reviewer = User::where('email', 'external.accept@example.test')->first();
        $this->assertNull($reviewer);

        $this->postJson("/api/reviewer-invitations/{$acceptAssignment->id}/accept", [
            'token' => 'wrong-token',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'This review invitation is invalid or expired.');
        $this->assertNull(User::where('email', 'external.accept@example.test')->first());

        $this->postJson("/api/reviewer-invitations/{$acceptAssignment->id}/accept", [
            'token' => $acceptToken,
        ])->assertOk();

        $createdReviewer = User::where('email', 'external.accept@example.test')->firstOrFail();
        $this->assertTrue($createdReviewer->hasRole('reviewer'));
        $this->assertDatabaseHas('reviewer_assignments', [
            'id' => $acceptAssignment->id,
            'reviewer_id' => $createdReviewer->id,
            'status' => 'accepted',
            'invite_token_hash' => null,
        ]);

        $declineToken = 'decline-token';
        $declineAssignment = $this->pendingInvitation('external.decline@example.test', $declineToken);

        $this->postJson("/api/reviewer-invitations/{$declineAssignment->id}/decline", [
            'token' => $declineToken,
            'decline_reason' => 'Conflict of interest.',
        ])->assertOk();

        $this->assertNull(User::where('email', 'external.decline@example.test')->first());
        $this->assertDatabaseHas('reviewer_assignments', [
            'id' => $declineAssignment->id,
            'reviewer_id' => null,
            'status' => 'declined',
            'decline_reason' => 'Conflict of interest.',
            'invite_token_hash' => null,
        ]);
    }

    public function test_reviewer_desk_shows_assignment_only_after_acceptance_and_required_questionnaire_is_enforced(): void
    {
        Sanctum::actingAs($this->admin);
        $questionnaire = $this->postJson('/api/admin/review-questionnaire', [
            'name' => 'Default Reviewer Form',
            'questions' => [[
                'prompt' => 'Is the method sound?',
                'response_type' => 'radio',
                'is_required' => true,
                'options' => ['Yes', 'No'],
            ]],
        ])->assertCreated()->json('questionnaire');
        $firstQuestionId = $questionnaire['active_version']['questions'][0]['id'];
        $firstVersionId = $questionnaire['active_version']['id'];

        $token = 'desk-token';
        $assignment = $this->pendingInvitation('desk.reviewer@example.test', $token);

        $reviewer = User::factory()->create([
            'email' => 'desk.reviewer@example.test',
            'role_id' => Role::where('name', 'reviewer')->value('id'),
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($reviewer);
        $this->getJson('/api/admin/my-reviewer-assignments')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->postJson("/api/reviewer-invitations/{$assignment->id}/accept", [
            'token' => $token,
        ])->assertOk();

        $this->getJson('/api/admin/my-reviewer-assignments')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $assignment->refresh();
        $this->assertSame($firstVersionId, $assignment->questionnaireInstance->review_questionnaire_version_id);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/admin/review-questionnaire', [
            'name' => 'Default Reviewer Form',
            'questions' => [[
                'prompt' => 'Updated required question',
                'response_type' => 'textarea',
                'is_required' => true,
            ]],
        ])->assertCreated();
        $this->assertSame(2, ReviewQuestionnaireVersion::count());

        Sanctum::actingAs($reviewer);
        $this->postJson("/api/admin/reviewer-assignments/{$assignment->id}/submit-review", [
            'scorecard' => ['originality' => 4],
            'recommendation' => 'accept',
            'comments_for_author' => 'Useful contribution.',
            'questionnaire_responses' => [],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Please answer all required reviewer questionnaire questions before submitting your review.');

        $this->postJson("/api/admin/reviewer-assignments/{$assignment->id}/submit-review", [
            'scorecard' => ['originality' => 4],
            'recommendation' => 'accept',
            'comments_for_author' => 'Useful contribution.',
            'questionnaire_responses' => [[
                'question_id' => $firstQuestionId,
                'answer' => 'yes',
            ]],
        ])->assertOk();

        $response = ReviewQuestionResponse::firstOrFail();
        $this->assertSame($assignment->questionnaire_instance_id, $response->review_questionnaire_instance_id);
        $this->assertSame('yes', $response->answer);

        Sanctum::actingAs($this->editor);
        $this->getJson("/api/admin/articles/{$this->article->id}/workflow")
            ->assertOk()
            ->assertJsonFragment(['prompt' => 'Is the method sound?'])
            ->assertJsonMissing(['prompt' => 'Updated required question']);

        Sanctum::actingAs($reviewer);
        $this->getJson("/api/admin/articles/{$this->article->id}/workflow")
            ->assertOk()
            ->assertJsonMissing(['private.reviewer@example.test']);
    }

    public function test_publication_metadata_sections_are_sanitized_and_public_payload_is_safe(): void
    {
        $this->article->update(['status' => ArticleStatus::READY_FOR_PUBLICATION]);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/articles/{$this->article->id}/publish", [
            'published_year' => 2026,
            'published_month' => 'July',
            'article_type' => 'Research Article',
            'open_access_label' => 'Open Access',
            'is_peer_reviewed' => true,
            'academic_editor' => 'Dr Editor',
            'received_at' => '2026-06-01',
            'accepted_at' => '2026-06-20',
            'license_statement' => 'CC BY 4.0',
            'data_availability_statement' => 'Data available on request.',
            'funding_statement' => 'No external funding.',
            'competing_interests_statement' => 'None declared.',
            'abbreviations' => 'AI: Artificial Intelligence',
            'citation_text' => 'Custom citation.',
            'doi' => '10.1234/example',
            'page_start' => 10,
            'page_end' => 20,
            'publication_sections' => [[
                'section_key' => 'introduction',
                'content_html' => '<h2 onclick="bad()">Intro</h2><p><a href="javascript:alert(1)">unsafe</a></p><script>alert(1)</script>',
            ]],
        ])->assertOk();

        ArticleAsset::create([
            'article_id' => $this->article->id,
            'asset_type' => 'image',
            'disk' => 's3',
            'file_path' => 'clean/articles/images/figure.webp',
            'storage_key' => 'clean/articles/images/figure.webp',
            'original_filename' => 'figure.webp',
            'safe_original_filename' => 'figure.webp',
            'title' => 'Figure 1',
            'caption' => 'Main result.',
            'file_size' => 1024,
            'mime_type' => 'image/webp',
            'scan_status' => 'clean',
        ]);

        $section = ArticlePublicationSection::where('article_id', $this->article->id)->firstOrFail();
        $this->assertStringNotContainsString('onclick', $section->content_html);
        $this->assertStringNotContainsString('javascript:', $section->content_html);
        $this->assertStringNotContainsString('<script>', $section->content_html);

        ArticleReviewerPreference::create([
            'article_id' => $this->article->id,
            'created_by_author_id' => $this->author->id,
            'type' => ArticleReviewerPreference::SUGGESTED,
            'name' => 'Private Reviewer',
            'email' => 'private.reviewer@example.test',
        ]);

        $this->getJson('/api/articles/workflow-completion-article')
            ->assertOk()
            ->assertJsonPath('article.tracking_code', $this->article->fresh()->tracking_code)
            ->assertJsonPath('article.open_access_label', 'Open Access')
            ->assertJsonPath('article.is_peer_reviewed', true)
            ->assertJsonPath('article.article_images.0.title', 'Figure 1')
            ->assertJsonPath('article.publication_sections.0.section_key', 'introduction')
            ->assertJsonMissing(['reviewer_preferences'])
            ->assertJsonMissing(['private.reviewer@example.test'])
            ->assertJsonMissing(['invite_token_hash']);
    }

    private function pendingInvitation(string $email, string $token): ReviewerAssignment
    {
        return ReviewerAssignment::create([
            'article_id' => $this->article->id,
            'reviewer_id' => null,
            'invitee_name' => 'External Reviewer',
            'invitee_email' => $email,
            'invite_token_hash' => hash('sha256', $token),
            'invited_at' => now(),
            'invite_expires_at' => now()->addDays(7),
            'assigned_by' => $this->editor->id,
            'status' => 'pending',
        ]);
    }
}
