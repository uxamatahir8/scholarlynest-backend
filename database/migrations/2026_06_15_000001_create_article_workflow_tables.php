<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sub_editor_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('sub_editor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('recommendation')->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'sub_editor_id']);
        });

        Schema::create('reviewer_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('sub_editor_assignment_id')->nullable()->constrained('sub_editor_assignments')->nullOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('due_date')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('recommendation')->nullable();
            $table->text('comments_for_author')->nullable();
            $table->text('confidential_comments')->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'reviewer_id']);
        });

        Schema::create('editorial_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('decision_by')->constrained('users')->cascadeOnDelete();
            $table->string('decision');
            $table->string('decision_source');
            $table->timestamp('decision_date');
            $table->text('comments_for_author')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('production_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role');
            $table->foreignId('assigned_by')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'user_id', 'role']);
        });

        Schema::create('post_publication_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->string('action_type');
            $table->text('reason');
            $table->text('notice_text');
            $table->foreignId('performed_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('article_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status_snapshot');
            $table->json('metadata_snapshot')->nullable();
            $table->text('change_summary')->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'version_number']);
        });

        Schema::create('article_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('article_version_id')->nullable()->constrained('article_versions')->nullOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('file_type');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->integer('size');
            $table->timestamps();

            $table->index(['article_id', 'file_type']);
        });

        Schema::create('article_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_audit_logs');
        Schema::dropIfExists('article_files');
        Schema::dropIfExists('article_versions');
        Schema::dropIfExists('post_publication_actions');
        Schema::dropIfExists('production_assignments');
        Schema::dropIfExists('editorial_decisions');
        Schema::dropIfExists('reviewer_assignments');
        Schema::dropIfExists('sub_editor_assignments');
    }
};
