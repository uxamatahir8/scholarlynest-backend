<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_review_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('article_version_id')->constrained('article_versions')->cascadeOnDelete();
            $table->unsignedInteger('round_number')->default(1);
            $table->string('status', 24)->default('pending');
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['article_version_id', 'round_number'], 'article_review_round_version_unique');
            $table->index(['article_id', 'status'], 'article_review_round_article_status_index');
        });

        Schema::table('reviewer_assignments', function (Blueprint $table) {
            $table->foreignId('review_round_id')->nullable()->after('article_version_id')->constrained('article_review_rounds')->restrictOnDelete();
            $table->index(['review_round_id', 'status'], 'reviewer_assignment_round_status_index');
        });

        DB::table('article_versions')->orderBy('id')->chunkById(200, function ($versions): void {
            foreach ($versions as $version) {
                $article = DB::table('articles')->where('id', $version->article_id)->first(['current_version_id', 'accepted_version_id']);
                $isCurrent = (int) ($article?->current_version_id ?? 0) === (int) $version->id;
                $isAccepted = (int) ($article?->accepted_version_id ?? 0) === (int) $version->id || (int) ($version->accepted_marker ?? 0) === 1;
                $open = $isCurrent && ! $isAccepted && ($version->screening_status === 'passed' || (int) ($version->revision_number ?? 0) > 0);
                $roundId = DB::table('article_review_rounds')->insertGetId([
                    'article_id' => $version->article_id,
                    'article_version_id' => $version->id,
                    'round_number' => 1,
                    'status' => $open ? 'open' : ($isCurrent ? 'pending' : 'closed'),
                    'opened_at' => $open ? ($version->submitted_at ?: $version->created_at) : null,
                    'closed_at' => ! $isCurrent ? now() : null,
                    'created_at' => $version->created_at,
                    'updated_at' => now(),
                ]);
                DB::table('reviewer_assignments')
                    ->where('article_version_id', $version->id)
                    ->where('round_number', 1)
                    ->whereNull('review_round_id')
                    ->update(['review_round_id' => $roundId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('reviewer_assignments', function (Blueprint $table) {
            $table->dropIndex('reviewer_assignment_round_status_index');
            $table->dropConstrainedForeignId('review_round_id');
        });
        Schema::dropIfExists('article_review_rounds');
    }
};
