<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('magazine_issues', function (Blueprint $table) {
            if (!Schema::hasColumn('magazine_issues', 'issue_month')) {
                $table->string('issue_month', 50)->nullable()->after('issue_number');
            }
            if (!Schema::hasColumn('magazine_issues', 'issue_year')) {
                $table->unsignedInteger('issue_year')->nullable()->after('issue_month');
            }
            if (!Schema::hasColumn('magazine_issues', 'cover_image')) {
                $table->string('cover_image')->nullable()->after('description');
            }
            if (!Schema::hasColumn('magazine_issues', 'status')) {
                $table->string('status')->default('draft')->after('cover_image');
            }
        });

        DB::table('magazine_issues')
            ->where('is_published', true)
            ->update(['status' => 'published']);
    }

    public function down(): void
    {
        Schema::table('magazine_issues', function (Blueprint $table) {
            $table->dropColumn(['issue_month', 'issue_year', 'cover_image', 'status']);
        });
    }
};
