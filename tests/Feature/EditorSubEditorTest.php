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
        $this->editor2->assignedSubEditors()->attach($this->subEditor->id); // Link to editor2 too

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

    public function test_super_admin_cannot_create_sub_editor_without_editor(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/admin/rbac/users', [
            'name' => 'Sub Editor Without Editor',
            'email' => 'sub_no_ed@example.com',
            'role_id' => $this->subEditorRole->id,
            'university_name' => 'Test University',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['editor_ids']);
    }

    public function test_super_admin_can_create_sub_editor_with_one_editor(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/admin/rbac/users', [
            'name' => 'Sub Editor One Editor',
            'email' => 'sub_one_ed@example.com',
            'role_id' => $this->subEditorRole->id,
            'university_name' => 'Test University',
            'editor_ids' => [$this->editor1->id],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('editor_sub_editor', [
            'editor_id' => $this->editor1->id,
            'sub_editor_id' => $response->json('data.id'),
        ]);
    }

    public function test_super_admin_can_create_sub_editor_with_multiple_editors(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/admin/rbac/users', [
            'name' => 'Sub Editor Multi Editor',
            'email' => 'sub_multi_ed@example.com',
            'role_id' => $this->subEditorRole->id,
            'university_name' => 'Test University',
            'editor_ids' => [$this->editor1->id, $this->editor2->id],
        ]);

        $response->assertStatus(201);
        $subId = $response->json('data.id');
        $this->assertDatabaseHas('editor_sub_editor', [
            'editor_id' => $this->editor1->id,
            'sub_editor_id' => $subId,
        ]);
        $this->assertDatabaseHas('editor_sub_editor', [
            'editor_id' => $this->editor2->id,
            'sub_editor_id' => $subId,
        ]);
    }

    public function test_super_admin_cannot_update_sub_editor_to_zero_editors(): void
    {
        Sanctum::actingAs($this->admin);
        
        $sub = User::factory()->create(['role_id' => $this->subEditorRole->id]);
        $this->editor1->assignedSubEditors()->attach($sub->id);

        $response = $this->patchJson("/api/admin/rbac/users/{$sub->id}", [
            'role_id' => $this->subEditorRole->id,
            'editor_ids' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['editor_ids']);
    }

    public function test_existing_user_receiving_sub_editor_role_requires_editor_assignment(): void
    {
        Sanctum::actingAs($this->admin);

        $user = User::factory()->create([
            'role_id' => Role::where('name', 'author')->first()->id
        ]);

        // Trying to update role to sub_editor without editor_ids should fail
        $response = $this->patchJson("/api/admin/rbac/users/{$user->id}", [
            'role_id' => $this->subEditorRole->id,
            'editor_ids' => [],
        ]);

        $response->assertStatus(422);

        // Succeeds with editor_ids
        $response2 = $this->patchJson("/api/admin/rbac/users/{$user->id}", [
            'role_id' => $this->subEditorRole->id,
            'editor_ids' => [$this->editor1->id],
        ]);
        $response2->assertOk();
        $this->assertDatabaseHas('editor_sub_editor', [
            'editor_id' => $this->editor1->id,
            'sub_editor_id' => $user->id,
        ]);
    }

    public function test_duplicate_pivot_link_cannot_be_created(): void
    {
        Sanctum::actingAs($this->editor1);

        // First link
        $this->postJson('/api/admin/editor/sub-editors', [
            'name' => 'Dupe Sub',
            'email' => 'dupe@example.com',
        ])->assertStatus(201);

        // Second link with same email should be idempotent (return 200 and not duplicate pivot row)
        $response = $this->postJson('/api/admin/editor/sub-editors', [
            'name' => 'Dupe Sub',
            'email' => 'dupe@example.com',
        ]);
        $response->assertStatus(200);

        $sub = User::where('email', 'dupe@example.com')->first();
        $this->assertEquals(1, $sub->assignedEditors()->count());
    }

    public function test_editor_cannot_unassign_final_editor_link(): void
    {
        $this->editor1->assignedSubEditors()->attach($this->subEditor->id);

        Sanctum::actingAs($this->editor1);

        $response = $this->postJson("/api/admin/editor/sub-editors/{$this->subEditor->id}/unassign");
        $response->assertStatus(422);
        $response->assertJsonPath('message', 'This Sub Editor must remain assigned to at least one Editor.');

        $this->assertDatabaseHas('editor_sub_editor', [
            'editor_id' => $this->editor1->id,
            'sub_editor_id' => $this->subEditor->id,
        ]);
    }

    public function test_orphan_sub_editor_is_excluded_from_workflow_assignment_candidates(): void
    {
        // subEditor has no editor links (orphan)
        Sanctum::actingAs($this->editor1);

        $response = $this->getJson('/api/admin/workflow/assignees?role=sub_editor');
        $response->assertOk();

        // subEditor is not in candidates list
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($this->subEditor->id, $ids);
    }

    public function test_editor_sees_only_their_linked_sub_editors_in_assignee_endpoint(): void
    {
        $sub1 = User::factory()->create(['role_id' => $this->subEditorRole->id]);
        $sub2 = User::factory()->create(['role_id' => $this->subEditorRole->id]);

        $this->editor1->assignedSubEditors()->attach($sub1->id);
        $this->editor2->assignedSubEditors()->attach($sub2->id);

        Sanctum::actingAs($this->editor1);
        $response = $this->getJson('/api/admin/workflow/assignees?role=sub_editor');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($sub1->id, $ids);
        $this->assertNotContains($sub2->id, $ids);
    }

    public function test_removing_sub_editor_role_entirely_detaches_editor_links(): void
    {
        $this->editor1->assignedSubEditors()->attach($this->subEditor->id);

        Sanctum::actingAs($this->admin);

        $authorRole = Role::where('name', 'author')->first();

        $response = $this->patchJson("/api/admin/rbac/users/{$this->subEditor->id}", [
            'role_id' => $authorRole->id,
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('editor_sub_editor', [
            'sub_editor_id' => $this->subEditor->id,
        ]);
    }

    public function test_transactional_safety_failed_link_does_not_leave_orphan_user(): void
    {
        Sanctum::actingAs($this->admin);

        // Send invalid editor ID (999999) to simulate a validation failure/sync error
        $response = $this->postJson('/api/admin/rbac/users', [
            'name' => 'Transactional Fail User',
            'email' => 'trans_fail@example.com',
            'role_id' => $this->subEditorRole->id,
            'university_name' => 'Test University',
            'editor_ids' => [999999], // non-existent editor id
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', [
            'email' => 'trans_fail@example.com',
        ]);
    }

    public function test_super_admin_cannot_call_editor_self_service_endpoints(): void
    {
        Sanctum::actingAs($this->admin); // super_admin

        $this->getJson('/api/admin/editor/sub-editors')->assertForbidden();
        $this->postJson('/api/admin/editor/sub-editors', [
            'name' => 'Subby',
            'email' => 'subby@example.com',
        ])->assertForbidden();
        
        $this->postJson("/api/admin/editor/sub-editors/{$this->subEditor->id}/unassign")->assertForbidden();
    }

    public function test_super_admin_cannot_be_assigned_as_editor_to_sub_editor(): void
    {
        Sanctum::actingAs($this->admin); // super_admin

        // Try to create a Sub Editor, passing the super admin ID as an editor_id
        $response = $this->postJson('/api/admin/rbac/users', [
            'name' => 'Sub Editor Assigned to Admin',
            'email' => 'sub_admin_assigned@example.com',
            'role_id' => $this->subEditorRole->id,
            'university_name' => 'Test University',
            'editor_ids' => [$this->admin->id], // admin is super_admin, not editor
        ]);

        $response->assertStatus(422);
    }
}
