<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            if (!Schema::hasColumn('articles', 'author_final_approved_at')) {
                $table->timestamp('author_final_approved_at')->nullable()->after('published_at');
            }

            if (!Schema::hasColumn('articles', 'author_final_approved_by')) {
                $table->foreignId('author_final_approved_by')->nullable()->after('author_final_approved_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            if (Schema::hasColumn('articles', 'author_final_approved_by')) {
                $table->dropConstrainedForeignId('author_final_approved_by');
            }

            if (Schema::hasColumn('articles', 'author_final_approved_at')) {
                $table->dropColumn('author_final_approved_at');
            }
        });
    }
};
