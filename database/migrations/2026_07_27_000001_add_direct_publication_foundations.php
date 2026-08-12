<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('submission_mode', 40)->default('editorial_workflow')->index()->after('status');
            $table->foreignId('directly_created_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('direct_publication_ready_at')->nullable()->after('published_at');
        });

        Schema::table('article_versions', function (Blueprint $table) {
            $table->string('source', 40)->default('editorial_workflow')->index()->after('label');
        });

        Schema::table('publication_records', function (Blueprint $table) {
            $table->unsignedBigInteger('accepted_file_set_id')->nullable()->change();
            $table->foreignId('magazine_id')->nullable()->after('article_id')->constrained('magazines')->restrictOnDelete();
            $table->string('publication_mode', 20)->default('workflow')->index()->after('proof_round_id');
            $table->foreignId('primary_publication_file_id')->nullable()->after('publication_mode')->constrained('article_files')->restrictOnDelete();
            $table->date('online_publication_date')->nullable()->after('page_end');
            $table->date('print_publication_date')->nullable()->after('online_publication_date');
            $table->foreignId('unpublished_by')->nullable()->after('unpublished_at')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('active_marker')->nullable()->after('status');
            $table->timestamp('publication_failed_at')->nullable();
            $table->string('publication_failure_code', 100)->nullable();
            $table->text('publication_failure_message')->nullable();
        });

        $latestRecordIds = DB::table('publication_records')->selectRaw('MAX(id) as latest_id')->groupBy('article_id')->pluck('latest_id')->map(fn ($id) => (int) $id)->all();
        DB::table('publication_records')->orderBy('id')->chunkById(200, function ($records) {
            foreach ($records as $record) {
                DB::table('publication_records')->where('id', $record->id)->update([
                    'magazine_id' => DB::table('articles')->where('id', $record->article_id)->value('magazine_id'),
                ]);
            }
        });
        if ($latestRecordIds !== []) {
            DB::table('publication_records')->whereIn('id', $latestRecordIds)->where('status', '!=', 'unpublished')->update(['active_marker' => 1]);
        }
        Schema::table('publication_records', fn (Blueprint $table) => $table->unique(['article_id', 'active_marker'], 'publication_record_one_active_unique'));

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE articles ADD CONSTRAINT articles_submission_mode_check CHECK (submission_mode IN ('editorial_workflow','direct_publication'))");
            DB::statement("ALTER TABLE publication_records ADD CONSTRAINT publication_mode_check CHECK (publication_mode IN ('workflow','direct'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE articles DROP CHECK articles_submission_mode_check');
            DB::statement('ALTER TABLE publication_records DROP CHECK publication_mode_check');
        }

        Schema::table('publication_records', function (Blueprint $table) {
            $table->dropUnique('publication_record_one_active_unique');
            $table->dropConstrainedForeignId('primary_publication_file_id');
            $table->dropConstrainedForeignId('unpublished_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('magazine_id');
            $table->dropColumn(['publication_mode', 'online_publication_date', 'print_publication_date', 'active_marker', 'publication_failed_at', 'publication_failure_code', 'publication_failure_message']);
            $table->unsignedBigInteger('accepted_file_set_id')->nullable(false)->change();
        });

        DB::table('articles')->whereNull('user_id')->whereNotNull('directly_created_by')->update(['user_id' => DB::raw('directly_created_by')]);

        Schema::table('article_versions', fn (Blueprint $table) => $table->dropColumn('source'));
        Schema::table('articles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('directly_created_by');
            $table->dropIndex(['submission_mode']);
            $table->dropColumn(['submission_mode', 'direct_publication_ready_at']);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
