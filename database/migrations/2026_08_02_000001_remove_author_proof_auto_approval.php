<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'articles.auto-approve')->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex(['author_final_review_due_at']);
            $table->dropColumn([
                'author_final_review_due_at',
                'author_final_auto_approved_at',
            ]);
        });

        Schema::table('proof_rounds', function (Blueprint $table) {
            $table->dropColumn('due_at');
        });
    }

    public function down(): void
    {
        Schema::table('proof_rounds', function (Blueprint $table) {
            $table->timestamp('due_at')->nullable()->after('requested_at');
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->timestamp('author_final_review_due_at')->nullable()->index()->after('author_final_review_requested_at');
            $table->timestamp('author_final_auto_approved_at')->nullable()->after('author_final_rejection_reason');
        });
    }
};
