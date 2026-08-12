<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviewer_assignments', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('accepted_at');
            $table->timestamp('closed_at')->nullable()->after('completed_at');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            $table->text('closure_reason')->nullable()->after('closed_by');
            $table->boolean('submitted_after_decision')->default(false)->after('closure_reason');
            $table->boolean('editorial_decision_existed_at_submission')->default(false)->after('submitted_after_decision');
            $table->index(['article_version_id', 'status', 'closed_at'], 'reviewer_version_pending_decision_index');
        });

        Schema::table('editorial_decisions', function (Blueprint $table) {
            $table->string('pending_review_policy', 32)->nullable()->after('internal_notes');
            $table->text('pending_review_override_reason')->nullable()->after('pending_review_policy');
            $table->unsignedInteger('pending_review_count')->default(0)->after('pending_review_override_reason');
            $table->json('pending_review_assignment_ids')->nullable()->after('pending_review_count');
        });
    }

    public function down(): void
    {
        Schema::table('editorial_decisions', function (Blueprint $table) {
            $table->dropColumn([
                'pending_review_policy',
                'pending_review_override_reason',
                'pending_review_count',
                'pending_review_assignment_ids',
            ]);
        });

        Schema::table('reviewer_assignments', function (Blueprint $table) {
            $table->dropIndex('reviewer_version_pending_decision_index');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn([
                'started_at',
                'closed_at',
                'closure_reason',
                'submitted_after_decision',
                'editorial_decision_existed_at_submission',
            ]);
        });
    }
};
