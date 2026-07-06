<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleAuditLog;
use App\Models\ArticleFile;
use App\Models\EditorialDecision;
use App\Models\Magazine;
use App\Models\NewsletterSubscriber;
use App\Models\Permission;
use App\Models\ProductionAssignment;
use App\Models\ReviewerAssignment;
use App\Models\Role;
use App\Models\SubEditorAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticatedPayloadMinimizationTest extends TestCase
{
    use RefreshDatabase;

    private Magazine $magazine;
    private Article $article;
    private User $author;
    private User $editor;
    private User $subEditor;
    private User $reviewer;
    private User $publisher;
    private User $copyEditor;
    private User $proofreader;
    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        foreach (['articles.view-own', 'articles.edit-own', 'articles.approve', 'articles.manage-assets', 'users.view-any', 'roles.view-any'] as $permission) {
            Permission::firstOrCreate(['name' => $permission], ['module' => explode('.', $permission)[0], 'description' => $permission]);
        }

        $this->author = $this->userWithRole('author', ['articles.view-own', 'articles.edit-own']);
        $this->editor = $this->userWithRole('editor', ['articles.view-own', 'articles.approve']);
        $this->subEditor = $this->userWithRole('sub_editor', ['articles.view-own']);
        $this->reviewer = $this->userWithRole('reviewer', ['articles.view-own']);
        $this->publisher = $this->userWithRole('publisher', ['articles.view-own', 'articles.approve']);
        $this->copyEditor = $this->userWithRole('copy_editor', ['articles.view-own']);
        $this->proofreader = $this->userWithRole('proofreader', ['articles.view-own']);
        $this->superAdmin = $this->userWithRole('super_admin', Permission::pluck('name')->all());

        $this->magazine = Magazine::create(['title' => 'Scoped Journal', 'slug' => 'scoped-journal']);
        $this->editor->magazines()->attach($this->magazine->id, ['role' => 'editor']);
        $this->publisher->magazines()->attach($this->magazine->id, ['role' => 'publisher']);

        $this->article = Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->author->id,
            'title' => 'Scoped Payload Article',
            'slug' => 'scoped-payload-article',
            'abstract' => 'Abstract',
            'full_text' => 'Private body',
            'status' => ArticleStatus::REVIEW_IN_PROGRESS,
            'pdf_path' => 'storage/articles/private.pdf',
            'plagiarism_report_path' => 'storage/reports/private.pdf',
        ]);

        DB::table('article_author')->insert([
            'article_id' => $this->article->id,
            'user_id' => $this->author->id,
            'co_author_name' => $this->author->name,
            'co_author_email' => $this->author->email,
            'can_edit' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        SubEditorAssignment::create([
            'article_id' => $this->article->id,
            'sub_editor_id' => $this->subEditor->id,
            'assigned_by' => $this->editor->id,
            'status' => 'completed',
            'recommendation' => 'minor_revision',
            'comments' => 'Sub editor internal notes',
        ]);

        ReviewerAssignment::create([
            'article_id' => $this->article->id,
            'reviewer_id' => $this->reviewer->id,
            'assigned_by' => $this->editor->id,
            'status' => 'completed',
            'recommendation' => 'accept',
            'comments_for_author' => 'Author-facing review',
            'confidential_comments' => 'Reviewer confidential comments',
            'scorecard' => ['quality' => 5],
        ]);

        ProductionAssignment::create([
            'article_id' => $this->article->id,
            'user_id' => $this->copyEditor->id,
            'assigned_by' => $this->publisher->id,
            'role' => 'copy_editor',
            'status' => 'pending',
        ]);

        EditorialDecision::create([
            'article_id' => $this->article->id,
            'decision_by' => $this->editor->id,
            'decision' => 'minor_revision',
            'decision_source' => 'editor',
            'decision_date' => now(),
            'comments_for_author' => 'Please revise.',
            'internal_notes' => 'Editor-only decision notes',
        ]);

        ArticleAuditLog::create([
            'article_id' => $this->article->id,
            'actor_id' => $this->editor->id,
            'event' => 'review.internal',
            'from_status' => ArticleStatus::SUBMITTED,
            'to_status' => ArticleStatus::REVIEW_IN_PROGRESS,
            'payload' => ['internal_note' => 'hidden'],
        ]);

        ArticleFile::create([
            'article_id' => $this->article->id,
            'uploaded_by' => $this->editor->id,
            'file_type' => ArticleFile::REVIEWED_MANUSCRIPT,
            'visibility' => 'reviewer_editor',
            'file_path' => 'storage/article-files/private-reviewed.pdf',
            'original_name' => 'reviewed.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'metadata' => ['storage_path' => 'private'],
        ]);
    }

    public function test_session_payload_excludes_tokens_hashes_and_verification_codes(): void
    {
        $this->author->update([
            'verification_code' => '111111',
            'two_factor_code' => '222222',
            'password_change_code' => '333333',
        ]);

        Sanctum::actingAs($this->author);

        $payload = $this->getJson('/api/me')->assertOk()->json();
        $this->assertPayloadHasNoSensitiveKeys($payload);
        $this->assertArrayHasKey('capabilities', $payload['user']);
        $this->assertArrayNotHasKey('permissions', $payload['user']);
        $this->assertArrayNotHasKey('permissions', $payload['user']['roles'][0]);
        $this->assertTrue($payload['user']['capabilities']['articles.view-own']);
    }

    public function test_normal_role_me_payload_uses_capabilities_without_rbac_permission_matrix(): void
    {
        Sanctum::actingAs($this->editor);

        $payload = $this->getJson('/api/me')->assertOk()->json('user');

        $this->assertArrayHasKey('capabilities', $payload);
        $this->assertArrayNotHasKey('permissions', $payload);
        $this->assertArrayNotHasKey('permissions', $payload['roles'][0]);
        $this->assertTrue($payload['capabilities']['articles.approve']);
        $this->assertFalse($payload['capabilities']['roles.view-any']);
    }

    public function test_admin_me_payload_may_include_rbac_permissions_for_admin_navigation(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $payload = $this->getJson('/api/me')->assertOk()->json('user');

        $this->assertArrayHasKey('permissions', $payload);
        $this->assertArrayHasKey('capabilities', $payload);
        $this->assertArrayNotHasKey('permissions', $payload['roles'][0]);
    }

    public function test_login_response_returns_only_intended_access_token_and_sanitized_user(): void
    {
        $this->author->update([
            'verification_code' => '111111',
            'two_factor_code' => '222222',
            'password_change_code' => '333333',
            'google_id' => 'google-provider-id',
        ]);

        $payload = $this->postJson('/api/login', [
            'email' => $this->author->email,
            'password' => 'Password123!',
        ])->assertOk()->json();

        $this->assertArrayHasKey('access_token', $payload);
        $this->assertSame('Bearer', $payload['token_type']);
        $this->assertArrayNotHasKey('refresh_token', $payload);
        $this->assertPayloadHasNoSensitiveKeys($payload['user']);
        $this->assertArrayNotHasKey('google_id', $payload['user']);
    }

    public function test_non_login_endpoints_do_not_return_access_tokens_or_private_credentials(): void
    {
        Sanctum::actingAs($this->author);

        foreach ([
            $this->getJson('/api/me'),
            $this->getJson("/api/admin/articles/{$this->article->id}"),
            $this->getJson("/api/admin/articles/{$this->article->id}/workflow"),
        ] as $response) {
            $response->assertOk();
            $json = $response->getContent();
            $this->assertStringNotContainsString('access_token', $json);
            $this->assertStringNotContainsString('refresh_token', $json);
            $this->assertStringNotContainsString('plainTextToken', $json);
        }
    }

    public function test_author_article_detail_excludes_reviewer_editor_assignments_audit_and_paths(): void
    {
        Sanctum::actingAs($this->author);

        $payload = $this->getJson("/api/admin/articles/{$this->article->id}")
            ->assertOk()
            ->assertJsonMissing(['confidential_comments' => 'Reviewer confidential comments'])
            ->assertJsonMissing(['internal_notes' => 'Editor-only decision notes'])
            ->assertJsonMissing(['plagiarism_report_path' => 'storage/reports/private.pdf'])
            ->json();

        $this->assertPayloadHasNoStoragePaths($payload);
        $this->assertArrayNotHasKey('reviewer_assignments', $payload);
        $this->assertArrayNotHasKey('audit_logs', $payload);
    }

    public function test_author_workflow_response_excludes_assignments_internal_notes_and_tokens(): void
    {
        Sanctum::actingAs($this->author);

        $payload = $this->getJson("/api/admin/articles/{$this->article->id}/workflow")
            ->assertOk()
            ->assertJsonMissing(['comments' => 'Sub editor internal notes'])
            ->assertJsonMissing(['confidential_comments' => 'Reviewer confidential comments'])
            ->assertJsonMissing(['internal_notes' => 'Editor-only decision notes'])
            ->json();

        $this->assertPayloadHasNoStoragePaths($payload);
        $this->assertSame([], $payload['article']['sub_editor_assignments']);
        $this->assertSame([], $payload['article']['reviewer_assignments']);
        $this->assertSame([], $payload['article']['production_assignments']);
        $this->assertSame([], $payload['article']['audit_logs']);
    }

    public function test_reviewer_workflow_context_excludes_other_internal_workflow_data(): void
    {
        Sanctum::actingAs($this->reviewer);

        $payload = $this->getJson("/api/admin/articles/{$this->article->id}/workflow")
            ->assertOk()
            ->assertJsonMissing(['comments' => 'Sub editor internal notes'])
            ->assertJsonMissing(['internal_notes' => 'Editor-only decision notes'])
            ->assertJsonMissing(['audit'])
            ->json();

        $this->assertPayloadHasNoStoragePaths($payload);
        $this->assertSame('Reviewer confidential comments', $payload['article']['reviewer_assignments'][0]['confidential_comments'] ?? null);
    }

    public function test_sub_editor_workflow_context_excludes_reviewer_confidential_comments_and_audit_logs(): void
    {
        Sanctum::actingAs($this->subEditor);

        $payload = $this->getJson("/api/admin/articles/{$this->article->id}/workflow")
            ->assertOk()
            ->assertJsonMissing(['confidential_comments' => 'Reviewer confidential comments'])
            ->assertJsonMissing(['internal_note' => 'hidden'])
            ->json();

        $this->assertPayloadHasNoStoragePaths($payload);
        $this->assertSame([], $payload['article']['audit_logs']);
    }

    public function test_editor_assignee_response_is_scoped_and_excludes_email(): void
    {
        DB::table('editor_sub_editor')->insert([
            'editor_id' => $this->editor->id,
            'sub_editor_id' => $this->subEditor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->editor);

        $payload = $this->getJson("/api/admin/workflow/assignees?role=sub_editor&magazine_id={$this->magazine->id}")
            ->assertOk()
            ->assertJsonFragment(['role' => 'sub_editor'])
            ->assertJsonMissing(['email' => $this->subEditor->email])
            ->json();

        $this->assertCount(1, $payload['data']);
        $this->assertSame($this->subEditor->id, $payload['data'][0]['id']);
        $this->assertArrayNotHasKey('email', $payload['data'][0]);
    }

    public function test_publisher_dashboard_excludes_review_stage_articles_and_workflow_fields(): void
    {
        Article::create([
            'magazine_id' => $this->magazine->id,
            'user_id' => $this->author->id,
            'title' => 'Ready Article',
            'slug' => 'ready-article',
            'abstract' => 'Abstract',
            'full_text' => 'Body',
            'status' => ArticleStatus::READY_FOR_PUBLICATION,
        ]);

        Sanctum::actingAs($this->publisher);

        $payload = $this->getJson('/api/admin/publisher-dashboard')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Scoped Payload Article'])
            ->assertJsonMissing(['confidential_comments' => 'Reviewer confidential comments'])
            ->json();

        $this->assertPayloadHasNoStoragePaths($payload);
    }

    public function test_copy_editor_and_proofreader_assignment_dashboards_are_own_task_scoped(): void
    {
        Sanctum::actingAs($this->copyEditor);
        $copyPayload = $this->getJson('/api/admin/my-production-assignments?role=copy_editor')->assertOk()->json();
        $this->assertCount(1, $copyPayload['data']);
        $this->assertPayloadHasNoStoragePaths($copyPayload);
        $this->assertStringNotContainsString('Reviewer confidential comments', json_encode($copyPayload));
        $this->assertStringNotContainsString('Editor-only decision notes', json_encode($copyPayload));

        Sanctum::actingAs($this->proofreader);
        $this->getJson('/api/admin/my-production-assignments?role=copy_editor')->assertForbidden();
    }

    public function test_newsletter_subscribe_and_admin_subscriber_list_exclude_unsubscribe_tokens(): void
    {
        $this->postJson('/api/newsletter/subscribe', [
            'email' => 'reader@example.test',
        ])->assertStatus(211)
            ->assertJsonPath('subscriber.email', 'reader@example.test')
            ->assertJsonMissingPath('subscriber.token');

        NewsletterSubscriber::create([
            'email' => 'admin-list@example.test',
            'token' => 'private-unsubscribe-token',
            'is_active' => true,
        ]);

        Permission::firstOrCreate(['name' => 'newsletters.view-any'], ['module' => 'newsletters', 'description' => 'View newsletters']);
        $this->superAdmin->role->permissions()->syncWithoutDetaching(Permission::where('name', 'newsletters.view-any')->pluck('id'));
        Sanctum::actingAs($this->superAdmin);

        $payload = $this->getJson('/api/admin/newsletter/subscribers')->assertOk()->json();
        $this->assertArrayKeysAbsent($payload, ['token']);
        $this->assertStringNotContainsString('private-unsubscribe-token', json_encode($payload));
    }

    public function test_safe_error_responses_do_not_include_internal_exception_details(): void
    {
        config(['app.debug' => false]);

        Route::get('/api/security-test-exception', function () {
            throw new \RuntimeException('SQLSTATE private failure /home/developer/workspace secret-token users table');
        });

        Sanctum::actingAs($this->author);
        $this->getJson('/api/admin/articles/999999')
            ->assertNotFound()
            ->assertJsonMissing(['exception'])
            ->assertJsonMissing(['trace'])
            ->assertJsonMissing(['file'])
            ->assertJsonMissing(['line']);

        Sanctum::actingAs($this->proofreader);
        $this->getJson('/api/admin/my-production-assignments?role=copy_editor')
            ->assertForbidden()
            ->assertJsonMissing(['exception'])
            ->assertJsonMissing(['trace'])
            ->assertJsonMissing(['file'])
            ->assertJsonMissing(['line']);

        $response = $this->getJson('/api/security-test-exception')->assertStatus(500);
        $json = $response->getContent();
        foreach (['SQLSTATE', '/home/developer', 'secret-token', 'users table', 'RuntimeException', 'trace', 'file', 'line'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
    }

    public function test_super_admin_rbac_user_payload_excludes_account_secrets(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $payload = $this->getJson('/api/admin/rbac/users')->assertOk()->json();
        $this->assertPayloadHasNoSensitiveKeys($payload);
    }

    private function userWithRole(string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => $roleName], [
            'display_name' => str_replace('_', ' ', ucfirst($roleName)),
            'is_system' => true,
        ]);
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

        return User::factory()->create([
            'role_id' => $role->id,
            'password' => Hash::make('Password123!'),
            'email_verified_at' => now(),
        ]);
    }

    private function assertPayloadHasNoStoragePaths(array $payload): void
    {
        $json = json_encode($payload);
        $this->assertStringNotContainsString('file_path', $json);
        $this->assertStringNotContainsString('pdf_path', $json);
        $this->assertStringNotContainsString('storage_path', $json);
        $this->assertStringNotContainsString('storage/article', $json);
        $this->assertStringNotContainsString('storage/reports', $json);
    }

    private function assertPayloadHasNoSensitiveKeys(array $payload): void
    {
        $forbiddenKeys = [
            'password',
            'remember_token',
            'verification_code',
            'verification_code_expires_at',
            'two_factor_code',
            'two_factor_code_expires_at',
            'password_change_code',
            'password_change_code_expires_at',
            'email_change_code',
            'new_email_verification_code',
            'token',
            'secret',
            'api_key',
        ];

        $this->assertArrayKeysAbsent($payload, $forbiddenKeys);
    }

    private function assertArrayKeysAbsent(array $payload, array $forbiddenKeys): void
    {
        foreach ($payload as $key => $value) {
            $this->assertNotContains($key, $forbiddenKeys, "Sensitive key [{$key}] was present in payload.");
            if (is_array($value)) {
                $this->assertArrayKeysAbsent($value, $forbiddenKeys);
            }
        }
    }
}
