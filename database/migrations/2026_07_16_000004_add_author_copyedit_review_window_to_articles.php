<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->timestamp('author_final_review_requested_at')->nullable()->after('author_final_approved_by');
            $table->timestamp('author_final_review_due_at')->nullable()->index()->after('author_final_review_requested_at');
            $table->timestamp('author_final_rejected_at')->nullable()->after('author_final_review_due_at');
            $table->text('author_final_rejection_reason')->nullable()->after('author_final_rejected_at');
            $table->timestamp('author_final_auto_approved_at')->nullable()->after('author_final_rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->dropIndex(['author_final_review_due_at']);
            $table->dropColumn([
                'author_final_review_requested_at',
                'author_final_review_due_at',
                'author_final_rejected_at',
                'author_final_rejection_reason',
                'author_final_auto_approved_at',
            ]);
        });
    }
};
