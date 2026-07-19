<?php

use App\Models\ArticleFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_versions', function (Blueprint $table) {
            $table->foreignId('manuscript_file_id')->nullable()->after('article_id')->index();
        });

        DB::table('article_versions')->orderBy('id')->each(function ($version) {
            $ids = DB::table('article_files')
                ->where('article_id', $version->article_id)
                ->where('article_version_id', $version->id)
                ->where('file_type', ArticleFile::MANUSCRIPT)
                ->where('scan_status', 'clean')
                ->whereNull('assignment_type')
                ->pluck('id');

            if ($ids->count() === 1) {
                DB::table('article_versions')->where('id', $version->id)->update([
                    'manuscript_file_id' => $ids->first(),
                ]);
            }
        });

        Schema::table('article_versions', function (Blueprint $table) {
            $table->foreign('manuscript_file_id')
                ->references('id')
                ->on('article_files')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('article_versions', function (Blueprint $table) {
            $table->dropForeign(['manuscript_file_id']);
            $table->dropColumn('manuscript_file_id');
        });
    }
};
