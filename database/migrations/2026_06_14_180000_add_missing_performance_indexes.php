<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->index('published_year');
            $table->index('published_month');
            $table->index(['published_year', 'published_month']);
        });

        Schema::table('magazine_user', function (Blueprint $table) {
            $table->index(['user_id', 'magazine_id']);
        });

        Schema::table('article_author', function (Blueprint $table) {
            $table->index(['article_id', 'user_id']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex(['published_year']);
            $table->dropIndex(['published_month']);
            $table->dropIndex(['published_year', 'published_month']);
        });

        Schema::table('magazine_user', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'magazine_id']);
        });

        Schema::table('article_author', function (Blueprint $table) {
            $table->dropIndex(['article_id', 'user_id']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
