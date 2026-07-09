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
            if (!Schema::hasColumn('articles', 'tracking_code')) {
                $table->string('tracking_code')->nullable()->after('id');
            }
            foreach ([
                'open_access_label' => fn () => $table->string('open_access_label')->nullable()->after('doi'),
                'is_peer_reviewed' => fn () => $table->boolean('is_peer_reviewed')->default(true)->after('open_access_label'),
                'academic_editor' => fn () => $table->string('academic_editor')->nullable()->after('is_peer_reviewed'),
                'received_at' => fn () => $table->date('received_at')->nullable()->after('academic_editor'),
                'accepted_at' => fn () => $table->date('accepted_at')->nullable()->after('received_at'),
                'license_statement' => fn () => $table->text('license_statement')->nullable()->after('accepted_at'),
                'competing_interests_statement' => fn () => $table->text('competing_interests_statement')->nullable()->after('license_statement'),
                'abbreviations' => fn () => $table->text('abbreviations')->nullable()->after('competing_interests_statement'),
                'citation_text' => fn () => $table->text('citation_text')->nullable()->after('abbreviations'),
            ] as $column => $callback) {
                if (!Schema::hasColumn('articles', $column)) {
                    $callback();
                }
            }
        });

        $articles = DB::table('articles')->select(['id', 'created_at', 'tracking_code'])->orderBy('id')->get();
        foreach ($articles as $article) {
            if ($article->tracking_code) {
                continue;
            }
            $year = $article->created_at ? date('Y', strtotime($article->created_at)) : date('Y');
            DB::table('articles')
                ->where('id', $article->id)
                ->update(['tracking_code' => sprintf('SN-%s-%06d', $year, $article->id)]);
        }

        Schema::table('articles', function (Blueprint $table) {
            if (!$this->indexExists('articles', 'articles_tracking_code_unique')) {
                $table->unique('tracking_code', 'articles_tracking_code_unique');
            }
        });

        Schema::table('article_assets', function (Blueprint $table) {
            if (!Schema::hasColumn('article_assets', 'asset_type')) {
                $table->string('asset_type')->default('supplementary')->after('article_id')->index();
            }
            if (!Schema::hasColumn('article_assets', 'title')) {
                $table->string('title')->nullable()->after('safe_original_filename');
            }
            if (!Schema::hasColumn('article_assets', 'caption')) {
                $table->text('caption')->nullable()->after('title');
            }
            if (!Schema::hasColumn('article_assets', 'description')) {
                $table->text('description')->nullable()->after('caption');
            }
            if (!Schema::hasColumn('article_assets', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('description');
            }
        });

        if (!Schema::hasTable('article_reviewer_preferences')) {
            Schema::create('article_reviewer_preferences', function (Blueprint $table) {
                $table->id();
                $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
                $table->foreignId('created_by_author_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('type', 20);
                $table->string('name');
                $table->string('email');
                $table->string('affiliation')->nullable();
                $table->string('designation')->nullable();
                $table->text('reason')->nullable();
                $table->timestamps();

                $table->unique(['article_id', 'type', 'email'], 'article_reviewer_pref_unique');
                $table->index(['article_id', 'type']);
            });
        }

        Schema::table('reviewer_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('reviewer_assignments', 'reviewer_id')) {
                try {
                    $table->dropForeign(['reviewer_id']);
                } catch (Throwable) {
                }
                try {
                    $table->unsignedBigInteger('reviewer_id')->nullable()->change();
                } catch (Throwable) {
                }
            }
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE reviewer_assignments MODIFY reviewer_id BIGINT UNSIGNED NULL');
        }

        Schema::table('reviewer_assignments', function (Blueprint $table) {
            if (!Schema::hasColumn('reviewer_assignments', 'invitee_name')) {
                $table->string('invitee_name')->nullable()->after('reviewer_id');
            }
            if (!Schema::hasColumn('reviewer_assignments', 'invitee_email')) {
                $table->string('invitee_email')->nullable()->after('invitee_name');
            }
            if (!Schema::hasColumn('reviewer_assignments', 'invite_token_hash')) {
                $table->string('invite_token_hash', 64)->nullable()->after('invitee_email');
            }
            if (!Schema::hasColumn('reviewer_assignments', 'invited_at')) {
                $table->timestamp('invited_at')->nullable()->after('invite_token_hash');
            }
            if (!Schema::hasColumn('reviewer_assignments', 'invite_expires_at')) {
                $table->timestamp('invite_expires_at')->nullable()->after('invited_at');
            }
            if (!Schema::hasColumn('reviewer_assignments', 'declined_at')) {
                $table->timestamp('declined_at')->nullable()->after('accepted_at');
            }
            if (!Schema::hasColumn('reviewer_assignments', 'decline_reason')) {
                $table->text('decline_reason')->nullable()->after('declined_at');
            }
            if (!Schema::hasColumn('reviewer_assignments', 'account_created_at')) {
                $table->timestamp('account_created_at')->nullable()->after('decline_reason');
            }
            if (!Schema::hasColumn('reviewer_assignments', 'questionnaire_instance_id')) {
                $table->unsignedBigInteger('questionnaire_instance_id')->nullable()->after('account_created_at');
            }
            if (!$this->indexExists('reviewer_assignments', 'reviewer_assignments_reviewer_id_foreign')) {
                $table->foreign('reviewer_id')->references('id')->on('users')->nullOnDelete();
            }
            if (!$this->indexExists('reviewer_assignments', 'reviewer_assignments_article_email_index')) {
                $table->index(['article_id', 'invitee_email'], 'reviewer_assignments_article_email_index');
            }
        });

        if (!Schema::hasTable('review_questionnaires')) {
            Schema::create('review_questionnaires', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->boolean('is_active')->default(false);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('review_questionnaire_versions')) {
            Schema::create('review_questionnaire_versions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('review_questionnaire_id');
                $table->unsignedInteger('version_number');
                $table->boolean('is_active')->default(false);
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
                $table->unique(['review_questionnaire_id', 'version_number'], 'review_questionnaire_version_unique');
                $table->foreign('review_questionnaire_id', 'rq_versions_questionnaire_fk')
                    ->references('id')
                    ->on('review_questionnaires')
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('review_questions')) {
            Schema::create('review_questions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('review_questionnaire_version_id');
                $table->string('prompt');
                $table->string('response_type', 40);
                $table->boolean('is_required')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->foreign('review_questionnaire_version_id', 'rq_questions_version_fk')
                    ->references('id')
                    ->on('review_questionnaire_versions')
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('review_question_options')) {
            Schema::create('review_question_options', function (Blueprint $table) {
                $table->id();
                $table->foreignId('review_question_id')->constrained('review_questions')->cascadeOnDelete();
                $table->string('label');
                $table->string('value');
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('review_questionnaire_instances')) {
            Schema::create('review_questionnaire_instances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
                $table->foreignId('reviewer_assignment_id')->constrained('reviewer_assignments')->cascadeOnDelete();
                $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedBigInteger('review_questionnaire_version_id');
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();
                $table->unique('reviewer_assignment_id', 'review_questionnaire_instance_assignment_unique');
                $table->foreign('review_questionnaire_version_id', 'rq_instances_version_fk')
                    ->references('id')
                    ->on('review_questionnaire_versions')
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('review_question_responses')) {
            Schema::create('review_question_responses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('review_questionnaire_instance_id');
                $table->foreignId('review_question_id')->constrained('review_questions')->cascadeOnDelete();
                $table->json('answer')->nullable();
                $table->timestamps();
                $table->unique(['review_questionnaire_instance_id', 'review_question_id'], 'review_question_response_unique');
                $table->foreign('review_questionnaire_instance_id', 'rq_responses_instance_fk')
                    ->references('id')
                    ->on('review_questionnaire_instances')
                    ->cascadeOnDelete();
            });
        }

        $this->ensureForeign('review_questionnaire_versions', 'rq_versions_questionnaire_fk', function (Blueprint $table) {
            $table->foreign('review_questionnaire_id', 'rq_versions_questionnaire_fk')
                ->references('id')
                ->on('review_questionnaires')
                ->cascadeOnDelete();
        });
        $this->ensureForeign('review_questions', 'rq_questions_version_fk', function (Blueprint $table) {
            $table->foreign('review_questionnaire_version_id', 'rq_questions_version_fk')
                ->references('id')
                ->on('review_questionnaire_versions')
                ->cascadeOnDelete();
        });
        $this->ensureForeign('review_questionnaire_instances', 'rq_instances_version_fk', function (Blueprint $table) {
            $table->foreign('review_questionnaire_version_id', 'rq_instances_version_fk')
                ->references('id')
                ->on('review_questionnaire_versions')
                ->cascadeOnDelete();
        });
        $this->ensureForeign('review_question_responses', 'rq_responses_instance_fk', function (Blueprint $table) {
            $table->foreign('review_questionnaire_instance_id', 'rq_responses_instance_fk')
                ->references('id')
                ->on('review_questionnaire_instances')
                ->cascadeOnDelete();
        });

        Schema::table('reviewer_assignments', function (Blueprint $table) {
            if (!$this->indexExists('reviewer_assignments', 'reviewer_assignments_questionnaire_instance_id_foreign')) {
                $table->foreign('questionnaire_instance_id', 'reviewer_assignments_questionnaire_instance_id_foreign')
                    ->references('id')
                    ->on('review_questionnaire_instances')
                    ->nullOnDelete();
            }
        });

        if (!Schema::hasTable('article_publication_sections')) {
            Schema::create('article_publication_sections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
                $table->string('section_key', 80);
                $table->longText('content_html')->nullable();
                $table->longText('content_text')->nullable();
                $table->timestamps();
                $table->unique(['article_id', 'section_key'], 'article_publication_section_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('article_publication_sections');
        Schema::dropIfExists('review_question_responses');
        Schema::table('reviewer_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('reviewer_assignments', 'questionnaire_instance_id')) {
                try {
                    $table->dropForeign('reviewer_assignments_questionnaire_instance_id_foreign');
                } catch (Throwable) {
                }
            }
        });
        Schema::dropIfExists('review_questionnaire_instances');
        Schema::dropIfExists('review_question_options');
        Schema::dropIfExists('review_questions');
        Schema::dropIfExists('review_questionnaire_versions');
        Schema::dropIfExists('review_questionnaires');
        Schema::dropIfExists('article_reviewer_preferences');

        Schema::table('reviewer_assignments', function (Blueprint $table) {
            foreach ([
                'questionnaire_instance_id',
                'account_created_at',
                'decline_reason',
                'declined_at',
                'invite_expires_at',
                'invited_at',
                'invite_token_hash',
                'invitee_email',
                'invitee_name',
            ] as $column) {
                if (Schema::hasColumn('reviewer_assignments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('article_assets', function (Blueprint $table) {
            foreach (['sort_order', 'description', 'caption', 'title', 'asset_type'] as $column) {
                if (Schema::hasColumn('article_assets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('articles', function (Blueprint $table) {
            try {
                $table->dropUnique('articles_tracking_code_unique');
            } catch (Throwable) {
            }
            foreach ([
                'citation_text',
                'abbreviations',
                'competing_interests_statement',
                'license_statement',
                'accepted_at',
                'received_at',
                'academic_editor',
                'is_peer_reviewed',
                'open_access_label',
                'tracking_code',
            ] as $column) {
                if (Schema::hasColumn('articles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            return collect(Schema::getIndexes($table))->contains(fn ($item) => ($item['name'] ?? null) === $index);
        } catch (Throwable) {
            return false;
        }
    }

    private function ensureForeign(string $table, string $index, Closure $callback): void
    {
        if ($this->indexExists($table, $index)) {
            return;
        }

        try {
            Schema::table($table, $callback);
        } catch (Throwable) {
        }
    }
};
