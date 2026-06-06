<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Role;
use App\Models\ContactMessage;
use App\Models\ContactReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ContactReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset Spatie cached permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Seed roles
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'admin', 'guard_name' => 'web']);
        Role::create(['name' => 'author', 'guard_name' => 'web']);
    }

    public function test_unauthorized_users_cannot_access_contact_messages_admin_routes(): void
    {
        // 1. Guest access to getMessages
        $response = $this->getJson('/api/admin/contact-messages');
        $response->assertStatus(401);

        // 2. Guest access to reply
        $response = $this->postJson('/api/admin/contact-messages/1/reply', [
            'subject' => 'Test Subject',
            'message' => 'Test Message'
        ]);
        $response->assertStatus(401);

        // 3. Authenticated non-admin access to getMessages
        $authorUser = User::factory()->create();
        $authorUser->assignRole('author');
        Sanctum::actingAs($authorUser);

        $response = $this->getJson('/api/admin/contact-messages');
        $response->assertStatus(403);

        // 4. Authenticated non-admin access to reply
        $response = $this->postJson('/api/admin/contact-messages/1/reply', [
            'subject' => 'Test Subject',
            'message' => 'Test Message'
        ]);
        $response->assertStatus(403);
    }

    public function test_admin_can_retrieve_contact_messages_with_replies(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        // Create a contact message
        $contactMessage = ContactMessage::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'subject' => 'Question about submission',
            'message' => 'Can you help me?',
            'status' => 'pending'
        ]);

        // Create a reply to it
        ContactReply::create([
            'contact_message_id' => $contactMessage->id,
            'user_id' => $admin->id,
            'subject' => 'Re: Question about submission',
            'message' => 'Sure, here is the help.'
        ]);

        $response = $this->getJson('/api/admin/contact-messages');

        $response->assertStatus(200)
                 ->assertJsonCount(1)
                 ->assertJsonFragment([
                     'name' => 'John Doe',
                     'email' => 'john@example.com',
                     'status' => 'pending'
                 ])
                 ->assertJsonStructure([
                     '*' => [
                         'id',
                         'name',
                         'email',
                         'subject',
                         'message',
                         'status',
                         'replies' => [
                             '*' => [
                                 'id',
                                 'contact_message_id',
                                 'user_id',
                                 'subject',
                                 'message',
                                 'created_at',
                                 'user' => [
                                     'id',
                                     'name',
                                     'email'
                                 ]
                             ]
                         ]
                     ]
                 ]);
    }

    public function test_admin_can_reply_to_contact_message(): void
    {
        Queue::fake();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        // Create a contact message
        $contactMessage = ContactMessage::create([
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
            'subject' => 'Partnership Inquiry',
            'message' => 'Would love to partner up.',
            'status' => 'pending'
        ]);

        $response = $this->postJson("/api/admin/contact-messages/{$contactMessage->id}/reply", [
            'subject' => 'Re: Partnership Inquiry',
            'message' => "Hi Jane,\n\nWe would love to partner with you too.\n\nBest,\nAdmin"
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('contact_message.status', 'replied')
                 ->assertJsonPath('reply.subject', 'Re: Partnership Inquiry');

        // Check contact message status was updated in database
        $this->assertDatabaseHas('contact_messages', [
            'id' => $contactMessage->id,
            'status' => 'replied'
        ]);

        // Check reply was stored in database
        $this->assertDatabaseHas('contact_replies', [
            'contact_message_id' => $contactMessage->id,
            'user_id' => $admin->id,
            'subject' => 'Re: Partnership Inquiry',
            'message' => "Hi Jane,\n\nWe would love to partner with you too.\n\nBest,\nAdmin"
        ]);

        // Check notification log created
        $this->assertDatabaseHas('notification_logs', [
            'recipient_email' => 'jane@example.com',
            'subject' => 'Re: Partnership Inquiry',
            'user_id' => $admin->id
        ]);

        // Check job dispatched
        Queue::assertPushed(\App\Jobs\SendNotificationJob::class);
    }

    public function test_reply_requires_subject_and_message(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $contactMessage = ContactMessage::create([
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
            'subject' => 'Partnership Inquiry',
            'message' => 'Would love to partner up.',
            'status' => 'pending'
        ]);

        // 1. Missing both
        $response = $this->postJson("/api/admin/contact-messages/{$contactMessage->id}/reply", []);
        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['subject', 'message']);

        // 2. Missing message
        $response = $this->postJson("/api/admin/contact-messages/{$contactMessage->id}/reply", [
            'subject' => 'Re: Partnership Inquiry'
        ]);
        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['message']);

        // 3. Missing subject
        $response = $this->postJson("/api/admin/contact-messages/{$contactMessage->id}/reply", [
            'message' => 'Reply content'
        ]);
        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['subject']);
    }
}
