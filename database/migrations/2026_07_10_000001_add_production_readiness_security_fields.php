<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'password_change_verified_at')) {
                $table->timestamp('password_change_verified_at')->nullable()->after('password_change_code_expires_at');
            }
            if (!Schema::hasColumn('users', 'password_change_failed_attempts')) {
                $table->unsignedTinyInteger('password_change_failed_attempts')->default(0)->after('password_change_verified_at');
            }
        });

        Schema::table('articles', function (Blueprint $table) {
            if (Schema::hasColumn('articles', 'magazine_id')) {
                $table->unsignedBigInteger('magazine_id')->nullable()->change();
            }
            if (Schema::hasColumn('articles', 'title')) {
                $table->string('title')->nullable()->change();
            }
            if (Schema::hasColumn('articles', 'abstract')) {
                $table->longText('abstract')->nullable()->change();
            }
        });

        Schema::table('article_publication_sections', function (Blueprint $table) {
            if (!Schema::hasColumn('article_publication_sections', 'title')) {
                $table->string('title')->nullable()->after('section_key');
            }
            if (!Schema::hasColumn('article_publication_sections', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('content_text');
            }
            if (!Schema::hasColumn('article_publication_sections', 'media_upload_session_id')) {
                $table->uuid('media_upload_session_id')->nullable()->after('sort_order');
            }
        });
    }

    public function down(): void
    {
        Schema::table('article_publication_sections', function (Blueprint $table) {
            foreach (['media_upload_session_id', 'sort_order', 'title'] as $column) {
                if (Schema::hasColumn('article_publication_sections', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            foreach (['password_change_failed_attempts', 'password_change_verified_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('articles', function (Blueprint $table) {
            if (Schema::hasColumn('articles', 'magazine_id')) {
                $table->unsignedBigInteger('magazine_id')->nullable(false)->change();
            }
            if (Schema::hasColumn('articles', 'title')) {
                $table->string('title')->nullable(false)->change();
            }
            if (Schema::hasColumn('articles', 'abstract')) {
                $table->longText('abstract')->nullable(false)->change();
            }
        });
    }
};
