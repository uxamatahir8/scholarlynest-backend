<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_versions', function (Blueprint $table) {
            $table->timestamp('accepted_at')->nullable()->after('status_snapshot');
            $table->foreignId('accepted_by')->nullable()->after('accepted_at')->constrained('users')->nullOnDelete();
            $table->index(['article_id', 'accepted_at']);
        });

        Schema::create('article_accepted_file_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('article_version_id')->constrained('article_versions')->restrictOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at');
            $table->string('selection_policy')->default('carry_forward_latest_per_purpose');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'superseded_at']);
        });

        Schema::create('article_accepted_file_set_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accepted_file_set_id')->constrained('article_accepted_file_sets')->cascadeOnDelete();
            $table->foreignId('article_file_id')->constrained('article_files')->restrictOnDelete();
            $table->foreignId('source_version_id')->constrained('article_versions')->restrictOnDelete();
            $table->string('accepted_role');
            $table->timestamps();

            $table->unique(['accepted_file_set_id', 'article_file_id'], 'accepted_set_file_unique');
            $table->index(['article_file_id', 'accepted_file_set_id'], 'accepted_file_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_accepted_file_set_items');
        Schema::dropIfExists('article_accepted_file_sets');

        Schema::table('article_versions', function (Blueprint $table) {
            $table->dropForeign(['accepted_by']);
            $table->dropIndex(['article_id', 'accepted_at']);
            $table->dropColumn(['accepted_at', 'accepted_by']);
        });
    }
};
