<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use App\Models\NewsletterSubscriber;
use App\Models\NewsletterCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NewsletterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset Spatie cached permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Seed roles
        $superAdminRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $adminRole = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        Role::create(['name' => 'author', 'guard_name' => 'web']);

        foreach (['newsletters.view-any', 'newsletters.send'] as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName], [
                'module' => 'newsletters',
                'description' => $permissionName,
            ]);
        }

        $superAdminRole->permissions()->sync(Permission::pluck('id'));
        $adminRole->permissions()->sync(Permission::whereIn('name', ['newsletters.view-any', 'newsletters.send'])->pluck('id'));
    }

    public function test_can_subscribe_to_newsletter(): void
    {
        Queue::fake();
 
        $response = $this->postJson('/api/newsletter/subscribe', [
            'email' => 'scholar@scholarlynest.com'
        ]);
 
        $response->assertStatus(211)
                 ->assertJson([
                     'message' => 'Thank you for subscribing to our newsletter!'
                 ]);
        $this->assertStringNotContainsString('token', $response->getContent());
 
        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'scholar@scholarlynest.com',
            'is_active' => true
        ]);
 
        $this->assertDatabaseHas('notification_logs', [
            'recipient_email' => 'scholar@scholarlynest.com',
            'subject' => 'Welcome to ScholarlyNest!',
        ]);
 
        Queue::assertPushed(\App\Jobs\SendNotificationJob::class);

        // Test duplicates
        $duplicateResponse = $this->postJson('/api/newsletter/subscribe', [
            'email' => 'scholar@scholarlynest.com'
        ]);

        $duplicateResponse->assertStatus(422)
                          ->assertJson([
                              'message' => 'This email is already subscribed to our newsletter!'
                          ]);
    }

    public function test_can_unsubscribe_from_newsletter(): void
    {
        $subscriber = NewsletterSubscriber::create([
            'email' => 'author@scholarlynest.com',
            'token' => 'unique-unsubscribe-token-123',
            'is_active' => true
        ]);

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'author@scholarlynest.com',
            'is_active' => true
        ]);

        $response = $this->get('/api/newsletter/unsubscribe/unique-unsubscribe-token-123');

        $response->assertStatus(200);
        $response->assertSee('Unsubscribed Successfully');

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'author@scholarlynest.com',
            'is_active' => false
        ]);

        // Test reactivation
        Queue::fake();
        $reactivateResponse = $this->postJson('/api/newsletter/subscribe', [
            'email' => 'author@scholarlynest.com'
        ]);
 
        $reactivateResponse->assertStatus(211)
                           ->assertJson([
                               'message' => 'Thank you for subscribing to our newsletter!'
                           ]);
 
        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'author@scholarlynest.com',
            'is_active' => true
        ]);
 
        $this->assertDatabaseHas('notification_logs', [
            'recipient_email' => 'author@scholarlynest.com',
            'subject' => 'Welcome to ScholarlyNest!',
        ]);
 
        Queue::assertPushed(\App\Jobs\SendNotificationJob::class);
    }

    public function test_can_unsubscribe_from_newsletter_via_json(): void
    {
        $subscriber = NewsletterSubscriber::create([
            'email' => 'author_json@scholarlynest.com',
            'token' => 'json-unsubscribe-token-999',
            'is_active' => true
        ]);

        $response = $this->getJson('/api/newsletter/unsubscribe/json-unsubscribe-token-999');

        $response->assertStatus(200)
                 ->assertJson([
                     'message' => 'You have been successfully removed from our mailing list.'
                 ]);

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'author_json@scholarlynest.com',
            'is_active' => false
        ]);

        // Test invalid token JSON response
        $errorResponse = $this->getJson('/api/newsletter/unsubscribe/non-existent-token');
        $errorResponse->assertStatus(400)
                      ->assertJson([
                          'message' => 'The unsubscribe link is invalid or has expired.'
                      ]);
    }

    public function test_unauthorized_users_cannot_access_newsletter_admin_routes(): void
    {
        $response = $this->getJson('/api/admin/newsletter/subscribers');
        $response->assertStatus(401);

        $nonAdminUser = User::factory()->create();
        Sanctum::actingAs($nonAdminUser);

        $response = $this->getJson('/api/admin/newsletter/subscribers');
        $response->assertStatus(403);
    }

    public function test_admin_can_manage_newsletter_campaigns(): void
    {
        Queue::fake();
 
        // Create admin user and assign role
        $admin = User::factory()->create();
        $admin->assignRole('admin');
 
        // Seed some subscribers
        NewsletterSubscriber::create([
            'email' => 'sub1@example.com'
        ]);
        NewsletterSubscriber::create([
            'email' => 'sub2@example.com'
        ]);
 
        Sanctum::actingAs($admin);
 
        // 1. List subscribers
        $response = $this->getJson('/api/admin/newsletter/subscribers');
        $response->assertStatus(200)
                 ->assertJsonCount(2);
        $this->assertStringNotContainsString('token', $response->getContent());
 
        // 2. Send campaign
        $campaignResponse = $this->postJson('/api/admin/newsletter/send', [
            'subject' => 'Magazine Volume 1',
            'content' => '<h1>New Volume Released!</h1>'
        ]);
 
        $campaignResponse->assertStatus(200)
                         ->assertJson([
                             'message' => 'Newsletter campaign successfully dispatched to 2 subscribers.'
                         ]);
 
        $this->assertDatabaseHas('notification_logs', [
            'recipient_email' => 'sub1@example.com',
            'subject' => 'Magazine Volume 1',
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'recipient_email' => 'sub2@example.com',
            'subject' => 'Magazine Volume 1',
        ]);
 
        Queue::assertPushed(\App\Jobs\SendNotificationJob::class, 2);

        // Check campaign logged in db
        $this->assertDatabaseHas('newsletter_campaigns', [
            'subject' => 'Magazine Volume 1',
            'recipients_count' => 2
        ]);

        // 3. List campaigns
        $campaignListResponse = $this->getJson('/api/admin/newsletter/campaigns');
        $campaignListResponse->assertStatus(200)
                             ->assertJsonCount(1)
                             ->assertJsonFragment([
                                 'subject' => 'Magazine Volume 1',
                                 'recipients_count' => 2
                             ]);
    }
}
