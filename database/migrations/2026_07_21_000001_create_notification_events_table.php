<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->string('deduplication_key', 191)->nullable()->unique();
            $table->string('event_type', 120)->index();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->foreignId('magazine_id')->nullable()->constrained('magazines')->nullOnDelete();
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('article_audit_log_id')->nullable()->constrained('article_audit_logs')->nullOnDelete();
            $table->json('payload');
            $table->timestamp('occurred_at', 6);
            $table->timestamp('available_at', 6);
            $table->timestamp('processing_at', 6)->nullable();
            $table->timestamp('processed_at', 6)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('last_error', 1000)->nullable();
            $table->timestamps(6);

            $table->index(['processed_at', 'available_at', 'id'], 'notification_events_pending_idx');
            $table->index(['event_type', 'occurred_at'], 'notification_events_type_idx');
            $table->index(['article_id', 'occurred_at'], 'notification_events_article_idx');
            $table->index(['subject_type', 'subject_id'], 'notification_events_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_events');
    }
};
