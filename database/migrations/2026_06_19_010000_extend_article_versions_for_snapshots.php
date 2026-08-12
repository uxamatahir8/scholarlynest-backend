<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_versions', function (Blueprint $table) {
            $table->string('label')->nullable()->after('version_number');
            $table->json('file_snapshot')->nullable()->after('metadata_snapshot');
            $table->text('author_response')->nullable()->after('change_summary');
        });
    }

    public function down(): void
    {
        Schema::table('article_versions', function (Blueprint $table) {
            $table->dropColumn(['label', 'file_snapshot', 'author_response']);
        });
    }
};
