<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EditorSubEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $editor1;
    private User $editor2;
    private User $subEditor;
    private Role $editorRole;
    private Role $subEditorRole;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles
        $superAdminRole = Role::create(['name' => 'super_admin', 'display_name' => 'Super Admin', 'is_system' => true]);
        $this->editorRole = Role::create(['name' => 'editor', 'display_name' => 'Editor', 'is_system' => true]);
        $this->subEditorRole = Role::create(['name' => 'sub_editor', 'display_name' => 'Sub Editor', 'is_system' => true]);
        $authorRole = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);

        Permission::firstOrCreate(
            ['name' => 'articles.view-own'],
            ['module' => 'articles', 'description' => 'articles.view-own']
        );

        $this->editorRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own'])->pluck('id'));
        $this->subEditorRole->permissions()->sync(Permission::whereIn('name', ['articles.view-own'])->pluck('id'));

        $this->admin = User::factory()->create(['role_id' => $superAdminRole->id]);
        $this->editor1 = User::factory()->create(['role_id' => $this->editorRole->id]);
        $this->editor2 = User::factory()->create(['role_id' => $this->editorRole->id]);
        $this->subEditor = User::factory()->create(['role_id' => $this->subEditorRole->id]);
    }

    public function test_editor_can_recruit_new_sub_editor(): void
    {
        Sanctum::actingAs($this->editor1);

        $response = $this->postJson('/api/admin/editor/sub-editors', [
            'name' => 'New Subby',
            'email' => 'newsub@example.com',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('sub_editor.name', 'New Subby');

        $this->assertDatabaseHas('users', [
            'email' => 'newsub@example.com',
            'role_id' => $this->subEditorRole->id,
        ]);

        $newSubId = $response->json('sub_editor.id');
        $this->assertDatabaseHas('editor_sub_editor', [
            'editor_id' => $this->editor1->id,
            'sub_editor_id' => $newSubId,
        ]);
    }

    public function test_editor_can_link_existing_user_and_upgrade_role(): void
    {
        $existingUser = User::factory()->create([
            'email' => 'existing@example.com',
            'role_id' => Role::where('name', 'author')->first()->id,
        ]);

        Sanctum::actingAs($this->editor1);

        $response = $this->postJson('/api/admin/editor/sub-editors', [
            'name' => 'Existing User',
            'email' => 'existing@example.com',
        ]);

        $response->assertStatus(201);
        
        $this->assertDatabaseHas('users', [
            'id' => $existingUser->id,
            'role_id' => $this->subEditorRole->id,
        ]);

        $this->assertDatabaseHas('editor_sub_editor', [
            'editor_id' => $this->editor1->id,
            'sub_editor_id' => $existingUser->id,
        ]);
    }

    public function test_multiple_editors_can_link_same_sub_editor(): void
    {
        Sanctum::actingAs($this->editor1);
        $this->postJson('/api/admin/editor/sub-editors', [
            'name' => 'Shared Sub Editor',
            'email' => 'sharedsub@example.com',
        ])->assertCreated();

        Sanctum::actingAs($this->editor2);
        $this->postJson('/api/admin/editor/sub-editors', [
            'name' => 'Shared Sub Editor',
            'email' => 'sharedsub@example.com',
        ])->assertCreated();

        $subEditor = User::where('email', 'sharedsub@example.com')->first();

        $this->assertDatabaseHas('editor_sub_editor', [
            'editor_id' => $this->editor1->id,
            'sub_editor_id' => $subEditor->id,
        ]);

        $this->assertDatabaseHas('editor_sub_editor', [
            'editor_id' => $this->editor2->id,
            'sub_editor_id' => $subEditor->id,
        ]);
    }

    public function test_editor_can_unassign_sub_editor(): void
    {
        $this->editor1->assignedSubEditors()->attach($this->subEditor->id);

        Sanctum::actingAs($this->editor1);

        $response = $this->postJson("/api/admin/editor/sub-editors/{$this->subEditor->id}/unassign");
        $response->assertOk();

        $this->assertDatabaseMissing('editor_sub_editor', [
            'editor_id' => $this->editor1->id,
            'sub_editor_id' => $this->subEditor->id,
        ]);
    }

    public function test_editor_sub_editors_list_is_scoped_to_recruits(): void
    {
        $sub1 = User::factory()->create(['role_id' => $this->subEditorRole->id]);
        $sub2 = User::factory()->create(['role_id' => $this->subEditorRole->id]);

        $this->editor1->assignedSubEditors()->attach($sub1->id);
        $this->editor2->assignedSubEditors()->attach($sub2->id);

        Sanctum::actingAs($this->editor1);

        $response = $this->getJson('/api/admin/editor/sub-editors');
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sub1->id);
    }

    public function test_unauthorized_roles_cannot_recruit_or_list_sub_editors(): void
    {
        Sanctum::actingAs($this->subEditor);

        $this->getJson('/api/admin/editor/sub-editors')->assertForbidden();
        $this->postJson('/api/admin/editor/sub-editors', [
            'name' => 'Subby',
            'email' => 'subby@example.com',
        ])->assertForbidden();
    }
}
