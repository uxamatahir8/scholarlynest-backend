<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_files', function (Blueprint $table) {
            $table->string('file_title')->nullable()->after('file_type');
            $table->uuid('media_upload_session_id')->nullable()->after('article_version_id');
            $table->unique('media_upload_session_id', 'article_files_upload_session_unique');
        });
    }

    public function down(): void
    {
        Schema::table('article_files', function (Blueprint $table) {
            $table->dropUnique('article_files_upload_session_unique');
            $table->dropColumn(['file_title', 'media_upload_session_id']);
        });
    }
};
