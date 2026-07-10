<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('issue_type');
            $table->string('title', 180);
            $table->text('details');
            $table->string('status')->default('submitted')->index();
            $table->string('priority')->nullable();
            $table->timestamp('last_reply_at')->nullable();
            $table->foreignId('last_replied_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['issue_type', 'status']);
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('message');
            $table->boolean('is_internal_note')->default(false);
            $table->timestamps();
        });

        Schema::create('support_ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('support_ticket_message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->uuid('media_upload_session_id');
            $table->foreignId('uploaded_by_id')->constrained('users')->cascadeOnDelete();
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamps();

            $table->foreign('media_upload_session_id')->references('id')->on('media_upload_sessions')->cascadeOnDelete();
            $table->index(['support_ticket_id', 'support_ticket_message_id'], 'sta_ticket_message_idx');
        });

        Schema::create('support_ticket_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('activity_type');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_activities');
        Schema::dropIfExists('support_ticket_attachments');
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
