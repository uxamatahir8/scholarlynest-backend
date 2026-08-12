<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('magazines', function (Blueprint $table) {
            if (!Schema::hasColumn('magazines', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('about_text')->index();
            }
        });

        Schema::create('article_transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_magazine_id')->constrained('magazines')->cascadeOnDelete();
            $table->foreignId('to_magazine_id')->constrained('magazines')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('responded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('pending')->index();
            $table->text('editor_comments');
            $table->text('author_rejection_reason')->nullable();
            $table->string('previous_article_status')->nullable();
            $table->string('next_article_status')->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'status']);
            $table->index('from_magazine_id');
            $table->index('to_magazine_id');
            $table->index('requested_by_user_id');
            $table->index('responded_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_transfer_requests');

        Schema::table('magazines', function (Blueprint $table) {
            if (Schema::hasColumn('magazines', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
