<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_files', function (Blueprint $table) {
            if (!Schema::hasColumn('article_files', 'source_asset_id')) {
                $table->foreignId('source_asset_id')->nullable()->after('article_version_id')->constrained('article_assets')->nullOnDelete();
            }
            if (!Schema::hasColumn('article_files', 'assignment_type')) {
                $table->string('assignment_type')->nullable()->after('uploaded_by');
            }
            if (!Schema::hasColumn('article_files', 'assignment_id')) {
                $table->unsignedBigInteger('assignment_id')->nullable()->after('assignment_type');
            }
            if (!Schema::hasColumn('article_files', 'visibility')) {
                $table->string('visibility')->default('workflow')->after('file_type');
            }
            if (!Schema::hasColumn('article_files', 'metadata')) {
                $table->json('metadata')->nullable()->after('size');
            }

            $table->index(['assignment_type', 'assignment_id'], 'article_files_assignment_index');
        });
    }

    public function down(): void
    {
        Schema::table('article_files', function (Blueprint $table) {
            if (Schema::hasColumn('article_files', 'assignment_type') && Schema::hasColumn('article_files', 'assignment_id')) {
                $table->dropIndex('article_files_assignment_index');
            }

            foreach (['metadata', 'visibility', 'assignment_id', 'assignment_type'] as $column) {
                if (Schema::hasColumn('article_files', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('article_files', 'source_asset_id')) {
                $table->dropConstrainedForeignId('source_asset_id');
            }
        });
    }
};
