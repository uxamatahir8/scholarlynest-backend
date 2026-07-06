<?php

namespace Tests\Feature;

use App\Constants\ArticleStatus;
use App\Models\Article;
use App\Models\Magazine;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArticleAcademicMetadataTest extends TestCase
{
    use RefreshDatabase;

    private Magazine $magazine;
    private User $author;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create(['name' => 'super_admin', 'display_name' => 'Super Admin', 'is_system' => true]);
        $authorRole = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);

        foreach (['articles.create', 'articles.view-own', 'articles.edit-own'] as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission],
                ['module' => 'articles', 'description' => $permission]
            );
        }

        $authorRole->permissions()->sync(Permission::whereIn('name', ['articles.create', 'articles.view-own', 'articles.edit-own'])->pluck('id'));

        $this->admin = User::factory()->create(['role_id' => $adminRole->id]);
        $this->author = User::factory()->create([
            'role_id' => $authorRole->id,
            'name' => 'Submitting Author',
            'email' => 'submitter@example.edu',
            'university_name' => 'Example University',
        ]);
        $this->magazine = Magazine::create([
            'title' => 'Metadata Journal',
            'slug' => 'metadata-journal',
            'description' => 'Metadata test journal',
        ]);
    }

    public function test_author_submission_stores_academic_metadata_and_owner_author(): void
    {
        Event::fake();
        Sanctum::actingAs($this->author);

        $this->postJson('/api/articles', array_merge($this->basePayload(), [
            'article_type' => 'Research Article',
            'subject_area' => 'Clinical Informatics',
            'language' => 'English',
            'ethical_approval_statement' => 'Approved by IRB 123.',
            'authors' => [[
                'name' => $this->author->name,
                'email' => strtoupper($this->author->email),
                'affiliation' => 'Example University',
                'department' => 'Biomedical Data',
                'country' => 'United States',
                'orcid' => '0000-0002-1825-0097',
                'is_owner' => true,
                'is_corresponding' => true,
                'contribution_statement' => 'Designed the study.',
            ]],
        ]))->assertStatus(211)
            ->assertJsonPath('article.status', ArticleStatus::SUBMITTED);

        $this->assertDatabaseHas('articles', [
            'title' => 'Metadata Submission',
            'user_id' => $this->author->id,
            'article_type' => 'Research Article',
            'subject_area' => 'Clinical Informatics',
        ]);

        $this->assertDatabaseHas('article_author', [
            'co_author_email' => 'submitter@example.edu',
            'is_owner' => true,
            'is_corresponding' => true,
            'author_order' => 1,
            'orcid' => '0000-0002-1825-0097',
        ]);
    }

    public function test_author_validation_requires_one_owner_and_corresponding_author(): void
    {
        Sanctum::actingAs($this->author);

        $this->postJson('/api/articles', array_merge($this->basePayload(), [
            'authors' => [[
                'name' => $this->author->name,
                'email' => $this->author->email,
                'is_owner' => false,
                'is_corresponding' => false,
            ]],
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('authors');
    }

    public function test_duplicate_author_emails_are_rejected(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/articles', array_merge($this->basePayload(), [
            'authors' => [
                ['name' => 'A One', 'email' => 'same@example.edu', 'is_owner' => true, 'is_corresponding' => true],
                ['name' => 'A Two', 'email' => 'SAME@example.edu', 'is_owner' => false, 'is_corresponding' => false],
            ],
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('authors');
    }

    public function test_super_admin_creates_missing_owner_author_account(): void
    {
        Event::fake();
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/articles', array_merge($this->basePayload(), [
            'authors' => [[
                'name' => 'New Owner',
                'email' => 'new.owner@example.edu',
                'affiliation' => 'New University',
                'is_owner' => true,
                'is_corresponding' => true,
            ]],
        ]))->assertStatus(211);

        $owner = User::where('email', 'new.owner@example.edu')->first();
        $this->assertNotNull($owner);
        $this->assertTrue((bool) $owner->needs_password_reset);

        $article = Article::where('title', 'Metadata Submission')->first();
        $this->assertEquals($owner->id, $article->user_id);
        $this->assertDatabaseHas('article_author', [
            'article_id' => $article->id,
            'user_id' => $owner->id,
            'is_owner' => true,
            'account_provisioned' => true,
        ]);
    }

    private function basePayload(): array
    {
        return [
            'magazine_id' => $this->magazine->id,
            'title' => 'Metadata Submission',
            'abstract' => 'Abstract text',
            'full_text' => 'Full manuscript text',
        ];
    }
}
