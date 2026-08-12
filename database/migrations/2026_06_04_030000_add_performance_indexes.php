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
            $table->index('status');
            $table->index('published_at');
            $table->index('created_at');
            $table->index(['magazine_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('magazines', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['published_at']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['magazine_id', 'status']);
            $table->dropIndex(['user_id', 'status']);
        });

        Schema::table('magazines', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->dropIndex(['name']);
        });
    }
};
