<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('notification_event_id')->constrained('notification_events')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 120);
            $table->string('category', 32);
            $table->string('priority', 16);
            $table->string('severity', 16);
            $table->string('privacy_variant', 40);
            $table->string('title_key', 160);
            $table->string('body_key', 160);
            $table->json('render_data');
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->foreignId('magazine_id')->nullable()->constrained('magazines')->nullOnDelete();
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('deep_link_key', 80)->nullable();
            $table->json('deep_link_params')->nullable();
            $table->string('group_key', 191)->nullable();
            $table->string('deduplication_key', 191);
            $table->boolean('in_app_visible')->default(true);
            $table->string('email_mode', 16)->default('off');
            $table->string('digest_frequency', 16)->nullable();
            $table->timestamp('email_queued_at', 6)->nullable();
            $table->timestamp('digest_sent_at', 6)->nullable();
            $table->timestamp('read_at', 6)->nullable();
            $table->timestamp('dismissed_at', 6)->nullable();
            $table->timestamp('archived_at', 6)->nullable();
            $table->string('action_status', 16)->default('none');
            $table->string('action_key', 80)->nullable();
            $table->timestamp('action_expires_at', 6)->nullable();
            $table->timestamp('action_completed_at', 6)->nullable();
            $table->timestamp('action_cancelled_at', 6)->nullable();
            $table->ulid('superseded_by_id')->nullable();
            $table->timestamp('expires_at', 6)->nullable();
            $table->timestamps(6);

            $table->unique(['recipient_user_id', 'deduplication_key'], 'user_notifications_recipient_dedupe');
            $table->unique(['notification_event_id', 'recipient_user_id', 'privacy_variant'], 'user_notifications_event_recipient_variant');
            $table->index(['recipient_user_id', 'archived_at', 'created_at', 'id'], 'user_notifications_feed_idx');
            $table->index(['recipient_user_id', 'read_at', 'archived_at'], 'user_notifications_unread_idx');
            $table->index(['recipient_user_id', 'action_status', 'action_expires_at'], 'user_notifications_action_idx');
            $table->index(['article_id', 'recipient_user_id', 'created_at'], 'user_notifications_article_idx');
            $table->index(['email_mode', 'digest_frequency', 'digest_sent_at'], 'user_notifications_digest_idx');
            $table->foreign('superseded_by_id')->references('id')->on('user_notifications')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
