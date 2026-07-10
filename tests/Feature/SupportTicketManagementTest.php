<?php

namespace Tests\Feature;

use App\Models\MediaUploadSession;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupportTicketManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $author;
    private User $otherAuthor;
    private User $admin;
    private User $supportUser;

    protected function setUp(): void
    {
        parent::setUp();

        config(['media_uploads.disk' => 'public', 'media_uploads.s3_prefix' => '']);
        Queue::fake();
        Storage::fake('public');

        $supportPermission = Permission::firstOrCreate(['name' => 'support_ticket_management'], [
            'module' => 'support',
            'description' => 'Manage support tickets',
        ]);

        $authorRole = Role::create(['name' => 'author', 'display_name' => 'Author', 'is_system' => true]);
        $adminRole = Role::create(['name' => 'admin', 'display_name' => 'Admin', 'is_system' => true]);
        $supportRole = Role::create(['name' => 'support_staff', 'display_name' => 'Support Staff', 'is_system' => true]);
        $adminRole->permissions()->sync([$supportPermission->id]);
        $supportRole->permissions()->sync([$supportPermission->id]);

        $this->author = User::factory()->create(['role_id' => $authorRole->id]);
        $this->otherAuthor = User::factory()->create(['role_id' => $authorRole->id]);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id]);
        $this->supportUser = User::factory()->create(['role_id' => $supportRole->id]);
    }

    public function test_authenticated_user_can_submit_ticket_with_clean_attachments(): void
    {
        $upload = $this->cleanUpload($this->author, 'diagnostics.pdf');
        $imageUpload = $this->cleanUpload($this->author, 'screenshot.png', [
            'declared_mime_type' => 'image/png',
            'detected_mime_type' => 'image/png',
        ]);

        Sanctum::actingAs($this->author);
        $response = $this->postJson('/api/support/tickets', [
            'issue_type' => 'technical_issue',
            'title' => 'Upload flow is blocked',
            'details' => 'The direct upload status never completes.',
            'attachments' => [$upload->id, $imageUpload->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('ticket.status', 'submitted')
            ->assertJsonCount(2, 'ticket.attachments')
            ->assertJsonPath('ticket.attachments.0.original_filename', 'diagnostics.pdf');

        $payload = $response->json();
        $encoded = json_encode($payload);
        $this->assertStringStartsWith('SUP-' . now()->format('Y') . '-', $payload['ticket']['ticket_number']);
        $this->assertStringNotContainsString('s3_clean_key', $encoded);
        $this->assertStringNotContainsString('clean/support/tickets', $encoded);

        $this->assertDatabaseHas('support_ticket_activities', ['activity_type' => 'ticket_created']);
        $this->assertDatabaseHas('support_ticket_activities', ['activity_type' => 'attachment_added']);
        $this->assertDatabaseHas('notification_logs', ['recipient_email' => $this->author->email]);
        $this->assertDatabaseHas('notification_logs', ['recipient_email' => $this->admin->email]);
        $this->assertDatabaseHas('notification_logs', ['recipient_email' => $this->supportUser->email]);
    }

    public function test_unauthenticated_and_invalid_ticket_requests_are_rejected(): void
    {
        $this->postJson('/api/support/tickets', [
            'issue_type' => 'technical_issue',
            'title' => 'Cannot sign in',
            'details' => 'Account help needed.',
        ])->assertUnauthorized();

        Sanctum::actingAs($this->author);
        $this->postJson('/api/support/tickets', [
            'issue_type' => 'invalid_problem',
            'title' => 'Invalid type',
            'details' => 'Invalid issue type should fail.',
        ])->assertUnprocessable();

        $this->postJson('/api/support/tickets', [
            'issue_type' => 'technical_issue',
            'title' => str_repeat('a', 181),
            'details' => str_repeat('b', 10001),
        ])->assertUnprocessable();
    }

    public function test_ticket_visibility_is_scoped_to_owner_or_support_permission(): void
    {
        $ownTicket = SupportTicket::create([
            'ticket_number' => '',
            'user_id' => $this->author->id,
            'issue_type' => 'account_issue',
            'title' => 'My ticket',
            'details' => 'Details',
            'status' => 'submitted',
        ]);
        $otherTicket = SupportTicket::create([
            'ticket_number' => '',
            'user_id' => $this->otherAuthor->id,
            'issue_type' => 'technical_issue',
            'title' => 'Other ticket',
            'details' => 'Details',
            'status' => 'submitted',
        ]);

        Sanctum::actingAs($this->author);
        $this->getJson('/api/support/tickets')
            ->assertOk()
            ->assertJsonFragment(['id' => $ownTicket->id])
            ->assertJsonMissing(['id' => $otherTicket->id]);
        $this->getJson("/api/support/tickets/{$otherTicket->id}")->assertForbidden();

        Sanctum::actingAs($this->supportUser);
        $this->getJson('/api/admin/support/tickets')
            ->assertOk()
            ->assertJsonFragment(['id' => $ownTicket->id])
            ->assertJsonFragment(['id' => $otherTicket->id]);
    }

    public function test_only_support_staff_can_update_status_and_owner_is_notified(): void
    {
        $ticket = $this->ticketFor($this->author);

        Sanctum::actingAs($this->author);
        $this->patchJson("/api/admin/support/tickets/{$ticket->id}/status", [
            'status' => 'in_review',
        ])->assertForbidden();

        Sanctum::actingAs($this->supportUser);
        $this->patchJson("/api/admin/support/tickets/{$ticket->id}/status", [
            'status' => 'closed',
        ])->assertOk()
            ->assertJsonPath('ticket.status', 'closed');

        $this->assertDatabaseHas('support_ticket_activities', [
            'support_ticket_id' => $ticket->id,
            'activity_type' => 'ticket_closed',
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'recipient_email' => $this->author->email,
            'subject' => "Support Ticket Status Changed: {$ticket->fresh()->ticket_number}",
        ]);
    }

    public function test_closed_ticket_blocks_owner_reply_but_allows_support_reply(): void
    {
        $ticket = $this->ticketFor($this->author, ['status' => 'closed']);
        $supportUpload = $this->cleanUpload($this->supportUser, 'support-note.pdf');
        $supportImage = $this->cleanUpload($this->supportUser, 'support-screenshot.webp', [
            'declared_mime_type' => 'image/webp',
            'detected_mime_type' => 'image/webp',
        ]);

        Sanctum::actingAs($this->author);
        $this->postJson("/api/support/tickets/{$ticket->id}/messages", [
            'message' => 'I need to add more information.',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Closed tickets cannot be replied to by the ticket owner.');

        Sanctum::actingAs($this->supportUser);
        $this->postJson("/api/admin/support/tickets/{$ticket->id}/messages", [
            'message' => 'Support can still add a closing note.',
            'attachments' => [$supportUpload->id, $supportImage->id],
        ])->assertCreated()
            ->assertJsonPath('reply.message', 'Support can still add a closing note.')
            ->assertJsonCount(2, 'reply.attachments');

        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'user_id' => $this->supportUser->id,
            'message' => 'Support can still add a closing note.',
        ]);
    }

    public function test_upload_sessions_must_be_clean_owned_and_support_ticket_purpose(): void
    {
        $otherUserUpload = $this->cleanUpload($this->otherAuthor, 'other.pdf');
        $wrongPurposeUpload = $this->cleanUpload($this->author, 'article.pdf', ['purpose' => 'article_supplementary']);
        $dirtyUpload = $this->cleanUpload($this->author, 'dirty.pdf', [
            'status' => MediaUploadSession::STATUS_UPLOADED_PENDING_SCAN,
        ]);

        Sanctum::actingAs($this->author);
        foreach ([$otherUserUpload, $wrongPurposeUpload, $dirtyUpload] as $upload) {
            $this->postJson('/api/support/tickets', [
                'issue_type' => 'technical_issue',
                'title' => 'Attachment rejection',
                'details' => 'This upload should not attach.',
                'attachments' => [$upload->id],
            ])->assertUnprocessable();
        }
    }

    public function test_attachment_download_requires_ticket_access(): void
    {
        $upload = $this->cleanUpload($this->author, 'receipt.pdf');
        Sanctum::actingAs($this->author);
        $attachmentId = $this->postJson('/api/support/tickets', [
            'issue_type' => 'payment_billing',
            'title' => 'Receipt question',
            'details' => 'I have a billing receipt question.',
            'attachments' => [$upload->id],
        ])->assertCreated()->json('ticket.attachments.0.id');

        $this->get("/api/support/tickets/attachments/{$attachmentId}/download")->assertOk();

        Sanctum::actingAs($this->otherAuthor);
        $this->getJson("/api/support/tickets/attachments/{$attachmentId}/download")->assertForbidden();

        Sanctum::actingAs($this->supportUser);
        $this->get("/api/support/tickets/attachments/{$attachmentId}/download")->assertOk();
    }

    private function ticketFor(User $user, array $overrides = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'ticket_number' => '',
            'user_id' => $user->id,
            'issue_type' => 'technical_issue',
            'title' => 'Ticket title',
            'details' => 'Ticket details',
            'status' => 'submitted',
        ], $overrides));
    }

    private function cleanUpload(User $user, string $filename, array $overrides = []): MediaUploadSession
    {
        $path = 'clean/support/tickets/' . $filename;
        Storage::disk('public')->put($path, 'support attachment');

        return MediaUploadSession::create(array_merge([
            'user_id' => $user->id,
            'purpose' => 'support_ticket_attachment',
            'original_filename' => $filename,
            'safe_display_filename' => $filename,
            'expected_size_bytes' => 1024,
            'declared_mime_type' => 'application/pdf',
            'detected_mime_type' => 'application/pdf',
            'disk' => 'public',
            's3_incoming_key' => 'incoming/support/tickets/' . $filename,
            's3_clean_key' => $path,
            'upload_mode' => 'single',
            'status' => MediaUploadSession::STATUS_CLEAN,
            'expires_at' => now()->addHour(),
        ], $overrides));
    }
}
