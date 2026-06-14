<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Modify the status column. On SQLite, we change to string with new default; on MySQL we run direct statement.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('articles', function (Blueprint $table) {
                $table->string('status')->default('submitted')->change();
            });
        } else {
            DB::statement("ALTER TABLE articles MODIFY COLUMN status ENUM('submitted', 'under_review', 'approved', 'published', 'minor_review_rejected', 'fully_rejected', 'resubmitted') NOT NULL DEFAULT 'submitted'");
        }

        // 2. Add published_year and published_month columns
        Schema::table('articles', function (Blueprint $table) {
            $table->integer('published_year')->nullable()->after('status');
            $table->string('published_month')->nullable()->after('published_year');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['published_year', 'published_month']);
        });

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('articles', function (Blueprint $table) {
                $table->string('status')->default('pending')->change();
            });
        } else {
            DB::statement("ALTER TABLE articles MODIFY COLUMN status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending'");
        }
    }
};
