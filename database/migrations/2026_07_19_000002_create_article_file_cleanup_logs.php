<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_file_cleanup_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('cleanup_run_id');
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->unsignedBigInteger('canonical_article_file_id')->nullable();
            $table->unsignedBigInteger('removed_article_file_id');
            $table->string('reason');
            $table->json('references_migrated')->nullable();
            $table->boolean('storage_deleted')->default(false);
            $table->string('performed_by')->default('artisan');
            $table->timestamp('performed_at');
            $table->timestamps();

            $table->index(['cleanup_run_id', 'article_id']);
            $table->unique(['removed_article_file_id'], 'article_file_cleanup_removed_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_file_cleanup_logs');
    }
};
