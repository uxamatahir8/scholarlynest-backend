<?php

use App\Constants\ArticleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('magazine_issues')) {
            Schema::create('magazine_issues', function (Blueprint $table) {
                $table->id();
                $table->foreignId('magazine_id')->constrained('magazines')->cascadeOnDelete();
                $table->unsignedInteger('volume_number');
                $table->unsignedInteger('issue_number');
                $table->string('special_title')->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_published')->default(false);
                $table->timestamp('published_at')->nullable();
                $table->timestamps();

                $table->unique(['magazine_id', 'volume_number', 'issue_number'], 'mag_issue_volume_issue_unique');
            });
        }

        Schema::table('articles', function (Blueprint $table) {
            if (!Schema::hasColumn('articles', 'magazine_issue_id')) {
                $table->foreignId('magazine_issue_id')->nullable()->after('magazine_id')->constrained('magazine_issues')->nullOnDelete();
            }
            if (!Schema::hasColumn('articles', 'subtitle')) {
                $table->string('subtitle')->nullable()->after('title');
            }
            if (!Schema::hasColumn('articles', 'keywords')) {
                $table->json('keywords')->nullable()->after('abstract');
            }
            if (!Schema::hasColumn('articles', 'doi')) {
                $table->string('doi')->nullable()->unique()->after('pdf_path');
            }
            if (!Schema::hasColumn('articles', 'page_start')) {
                $table->unsignedInteger('page_start')->nullable()->after('published_month');
            }
            if (!Schema::hasColumn('articles', 'page_end')) {
                $table->unsignedInteger('page_end')->nullable()->after('page_start');
            }
            if (!Schema::hasColumn('articles', 'plagiarism_status')) {
                $table->string('plagiarism_status')->nullable()->after('rejection_reason');
            }
            if (!Schema::hasColumn('articles', 'plagiarism_score')) {
                $table->decimal('plagiarism_score', 5, 2)->nullable()->after('plagiarism_status');
            }
            if (!Schema::hasColumn('articles', 'plagiarism_report_path')) {
                $table->string('plagiarism_report_path')->nullable()->after('plagiarism_score');
            }
            if (!Schema::hasColumn('articles', 'screened_at')) {
                $table->timestamp('screened_at')->nullable()->after('plagiarism_report_path');
            }
            if (!Schema::hasColumn('articles', 'screened_by')) {
                $table->foreignId('screened_by')->nullable()->after('screened_at')->constrained('users')->nullOnDelete();
            }
        });

        $this->normalizeArticleStatuses();

        Schema::table('article_author', function (Blueprint $table) {
            if (!Schema::hasColumn('article_author', 'affiliation')) {
                $table->string('affiliation')->nullable()->after('co_author_email');
            }
            if (!Schema::hasColumn('article_author', 'department')) {
                $table->string('department')->nullable()->after('affiliation');
            }
            if (!Schema::hasColumn('article_author', 'country')) {
                $table->string('country')->nullable()->after('department');
            }
            if (!Schema::hasColumn('article_author', 'orcid')) {
                $table->string('orcid')->nullable()->after('country');
            }
            if (!Schema::hasColumn('article_author', 'is_owner')) {
                $table->boolean('is_owner')->default(false)->after('orcid');
            }
            if (!Schema::hasColumn('article_author', 'is_corresponding')) {
                $table->boolean('is_corresponding')->default(false)->after('is_owner');
            }
        });

        DB::table('articles')
            ->select(['id', 'user_id'])
            ->orderBy('id')
            ->chunkById(100, function ($articles) {
                foreach ($articles as $article) {
                    $exists = DB::table('article_author')
                        ->where('article_id', $article->id)
                        ->where('user_id', $article->user_id)
                        ->exists();

                    if (!$exists) {
                        $user = DB::table('users')->where('id', $article->user_id)->first();
                        DB::table('article_author')->insert([
                            'article_id' => $article->id,
                            'user_id' => $article->user_id,
                            'co_author_name' => $user?->name ?? 'Primary Author',
                            'co_author_email' => $user?->email ?? "article-{$article->id}@example.invalid",
                            'can_edit' => true,
                            'account_provisioned' => true,
                            'is_owner' => true,
                            'is_corresponding' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    } else {
                        DB::table('article_author')
                            ->where('article_id', $article->id)
                            ->where('user_id', $article->user_id)
                            ->update([
                                'is_owner' => true,
                                'is_corresponding' => true,
                                'updated_at' => now(),
                            ]);
                    }
                }
            });

        Schema::table('magazine_user', function (Blueprint $table) {
            if (!Schema::hasColumn('magazine_user', 'role')) {
                $table->string('role')->nullable()->after('magazine_id');
            }
            if (!Schema::hasColumn('magazine_user', 'assigned_by')) {
                $table->foreignId('assigned_by')->nullable()->after('role')->constrained('users')->nullOnDelete();
            }
        });

        if (!Schema::hasColumn('roles', 'description')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->text('description')->nullable()->after('display_name');
            });
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (Schema::hasColumn('roles', 'description')) {
                $table->dropColumn('description');
            }
        });

        Schema::table('magazine_user', function (Blueprint $table) {
            if (Schema::hasColumn('magazine_user', 'assigned_by')) {
                $table->dropConstrainedForeignId('assigned_by');
            }
            if (Schema::hasColumn('magazine_user', 'role')) {
                $table->dropColumn('role');
            }
        });

        Schema::table('article_author', function (Blueprint $table) {
            foreach (['affiliation', 'department', 'country', 'orcid', 'is_owner', 'is_corresponding'] as $column) {
                if (Schema::hasColumn('article_author', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('articles', function (Blueprint $table) {
            foreach ([
                'subtitle',
                'keywords',
                'doi',
                'page_start',
                'page_end',
                'plagiarism_status',
                'plagiarism_score',
                'plagiarism_report_path',
                'screened_at',
            ] as $column) {
                if (Schema::hasColumn('articles', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('articles', 'screened_by')) {
                $table->dropConstrainedForeignId('screened_by');
            }
            if (Schema::hasColumn('articles', 'magazine_issue_id')) {
                $table->dropConstrainedForeignId('magazine_issue_id');
            }
        });

        Schema::dropIfExists('magazine_issues');
    }

    private function normalizeArticleStatuses(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE articles MODIFY COLUMN status VARCHAR(64) NOT NULL DEFAULT '" . ArticleStatus::SUBMITTED . "'");
        }

        foreach (ArticleStatus::LEGACY_MAP as $old => $new) {
            DB::table('articles')->where('status', $old)->update(['status' => $new]);
        }
    }
};
