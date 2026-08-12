<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('article_version_id')->nullable()->constrained('article_versions')->restrictOnDelete();
            $table->string('context_type', 60)->default('article');
            $table->unsignedBigInteger('context_id')->nullable();
            $table->string('thread_type', 60);
            $table->string('privacy_classification', 60);
            $table->string('title', 180);
            $table->string('status', 20)->default('active');
            $table->string('default_key', 190)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['article_id', 'status', 'last_message_at'], 'article_threads_list_index');
            $table->index(['article_version_id', 'thread_type'], 'article_threads_version_type_index');
            $table->index(['context_type', 'context_id'], 'article_threads_context_index');
            $table->index(['privacy_classification', 'status'], 'article_threads_privacy_index');
        });

        Schema::create('article_thread_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('article_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('participant_role', 40);
            $table->string('access_level', 20)->default('reply');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('added_at');
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->timestamp('muted_at')->nullable();
            $table->string('notification_preference', 20)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['thread_id', 'user_id'], 'article_thread_participant_unique');
            $table->index(['user_id', 'removed_at', 'thread_id'], 'article_thread_participant_access_index');
        });

        Schema::create('article_thread_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('article_threads')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('parent_message_id')->nullable()->constrained('article_thread_messages')->restrictOnDelete();
            $table->string('message_type', 40)->default('user_message');
            $table->text('body');
            $table->string('body_format', 20)->default('plain_text');
            $table->string('audience_variant', 60);
            $table->boolean('is_system')->default(false);
            $table->string('event_key', 120)->nullable();
            $table->string('client_request_id', 100)->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['thread_id', 'created_at', 'id'], 'article_thread_messages_page_index');
            $table->index(['sender_id', 'created_at'], 'article_thread_messages_sender_index');
            $table->unique(['thread_id', 'sender_id', 'client_request_id'], 'article_thread_message_idempotency_unique');
        });

        Schema::create('article_thread_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('article_thread_messages')->cascadeOnDelete();
            $table->foreignId('article_file_id')->constrained('article_files')->restrictOnDelete();
            $table->string('safe_filename');
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->string('scan_status', 30);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility_classification', 60);
            $table->timestamps();
            $table->unique(['message_id', 'article_file_id'], 'article_thread_attachment_unique');
        });

        Schema::create('article_thread_read_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('article_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('last_read_message_id')->nullable()->constrained('article_thread_messages')->nullOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();
            $table->unique(['thread_id', 'user_id'], 'article_thread_read_state_unique');
        });

        Schema::create('article_thread_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('article_thread_messages')->cascadeOnDelete();
            $table->foreignId('mentioned_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->unique(['message_id', 'mentioned_user_id'], 'article_thread_mention_unique');
            $table->index(['mentioned_user_id', 'created_at'], 'article_thread_mentions_user_index');
        });

        Schema::create('article_thread_message_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('article_thread_messages')->cascadeOnDelete();
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('previous_body');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_thread_message_revisions');
        Schema::dropIfExists('article_thread_mentions');
        Schema::dropIfExists('article_thread_read_states');
        Schema::dropIfExists('article_thread_message_attachments');
        Schema::dropIfExists('article_thread_messages');
        Schema::dropIfExists('article_thread_participants');
        Schema::dropIfExists('article_threads');
    }
};
