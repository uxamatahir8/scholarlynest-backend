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
            $table->string('lifecycle_status', 80)->nullable()->after('status')->index();
            $table->foreignId('current_version_id')->nullable()->after('lifecycle_status')->constrained('article_versions')->nullOnDelete();
            $table->foreignId('accepted_version_id')->nullable()->after('current_version_id')->constrained('article_versions')->nullOnDelete();
            $table->unsignedBigInteger('lifecycle_sequence')->default(0)->after('accepted_version_id');
            $table->index(['magazine_id', 'lifecycle_status', 'updated_at'], 'articles_lifecycle_dashboard_index');
        });

        Schema::table('article_versions', function (Blueprint $table) {
            $table->foreignId('parent_version_id')->nullable()->after('article_id')->constrained('article_versions')->restrictOnDelete();
            $table->string('screening_status', 32)->default('pending')->after('status_snapshot');
            $table->timestamp('screened_at')->nullable()->after('screening_status');
            $table->foreignId('screened_by')->nullable()->after('screened_at')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('screened_by');
            $table->timestamp('locked_at')->nullable()->after('submitted_at');
            $table->unsignedTinyInteger('accepted_marker')->nullable()->after('locked_at');
            $table->index(['article_id', 'screening_status', 'version_number'], 'article_versions_screening_index');
            $table->unique(['article_id', 'accepted_marker'], 'article_versions_one_accepted_unique');
        });

        Schema::table('article_accepted_file_sets', function (Blueprint $table) {
            $table->unsignedTinyInteger('active_marker')->nullable()->after('superseded_at');
            $table->unique(['article_id', 'active_marker'], 'accepted_file_sets_one_active_unique');
        });

        Schema::table('sub_editor_assignments', function (Blueprint $table) {
            $table->index('article_id', 'sub_editor_article_fk_support_index');
            $table->index('sub_editor_id', 'sub_editor_user_fk_support_index');
            $table->dropUnique(['article_id', 'sub_editor_id']);
            $table->foreignId('article_version_id')->nullable()->after('article_id')->constrained('article_versions')->restrictOnDelete();
            $table->unsignedInteger('round_number')->default(1)->after('article_version_id');
            $table->text('author_comments')->nullable()->after('comments');
            $table->text('internal_comments')->nullable()->after('author_comments');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('sub_editor_assignments')->nullOnDelete();
            $table->string('idempotency_key', 100)->nullable();
            $table->index(['article_version_id', 'status', 'round_number'], 'sub_editor_version_status_index');
            $table->unique('idempotency_key', 'sub_editor_idempotency_unique');
        });

        Schema::table('reviewer_assignments', function (Blueprint $table) {
            $table->index('article_id', 'reviewer_article_fk_support_index');
            $table->index('reviewer_id', 'reviewer_user_fk_support_index');
            $table->dropUnique(['article_id', 'reviewer_id']);
            $table->foreignId('article_version_id')->nullable()->after('article_id')->constrained('article_versions')->restrictOnDelete();
            $table->unsignedInteger('round_number')->default(1)->after('article_version_id');
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('reminder_count')->default(0);
            $table->timestamp('last_reminded_at')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->index(['article_version_id', 'round_number', 'status'], 'reviewer_version_round_status_index');
            $table->index(['invite_expires_at', 'status'], 'reviewer_invitation_expiry_index');
            $table->unique('idempotency_key', 'reviewer_idempotency_unique');
        });

        Schema::table('editorial_decisions', function (Blueprint $table) {
            $table->foreignId('article_version_id')->nullable()->after('article_id')->constrained('article_versions')->restrictOnDelete();
            $table->unsignedInteger('round_number')->default(1)->after('article_version_id');
            $table->timestamp('revision_due_at')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->foreignId('corrects_decision_id')->nullable()->constrained('editorial_decisions')->restrictOnDelete();
            $table->index(['article_version_id', 'round_number', 'decision'], 'editorial_version_round_index');
            $table->unique('idempotency_key', 'editorial_decision_idempotency_unique');
        });

        Schema::table('production_assignments', function (Blueprint $table) {
            $table->index('article_id', 'production_article_fk_support_index');
            $table->index('user_id', 'production_user_fk_support_index');
            $table->dropUnique(['article_id', 'user_id', 'role']);
            $table->foreignId('article_version_id')->nullable()->after('article_id')->constrained('article_versions')->restrictOnDelete();
            $table->foreignId('accepted_file_set_id')->nullable()->after('article_version_id')->constrained('article_accepted_file_sets')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->index(['user_id', 'role', 'status'], 'production_user_queue_index');
            $table->unique('idempotency_key', 'production_idempotency_unique');
        });

        Schema::create('proof_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('article_version_id')->constrained('article_versions')->restrictOnDelete();
            $table->foreignId('accepted_file_set_id')->constrained('article_accepted_file_sets')->restrictOnDelete();
            $table->foreignId('production_assignment_id')->nullable()->constrained('production_assignments')->nullOnDelete();
            $table->unsignedInteger('round_number');
            $table->string('status', 40)->default('draft');
            $table->foreignId('source_file_id')->nullable()->constrained('article_files')->restrictOnDelete();
            $table->foreignId('author_file_id')->nullable()->constrained('article_files')->restrictOnDelete();
            $table->foreignId('corrected_file_id')->nullable()->constrained('article_files')->restrictOnDelete();
            $table->text('author_comments')->nullable();
            $table->text('production_notes')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->unsignedTinyInteger('active_marker')->nullable();
            $table->timestamps();
            $table->unique(['article_id', 'round_number'], 'proof_round_article_number_unique');
            $table->index(['article_id', 'status'], 'proof_round_article_status_index');
            $table->unique(['article_id', 'active_marker'], 'proof_round_one_active_unique');
        });

        Schema::create('publication_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('article_version_id')->constrained('article_versions')->restrictOnDelete();
            $table->foreignId('accepted_file_set_id')->constrained('article_accepted_file_sets')->restrictOnDelete();
            $table->foreignId('proof_round_id')->nullable()->constrained('proof_rounds')->restrictOnDelete();
            $table->foreignId('magazine_issue_id')->nullable()->constrained('magazine_issues')->restrictOnDelete();
            $table->string('status', 40)->default('preparing');
            $table->string('doi')->nullable();
            $table->unsignedInteger('page_start')->nullable();
            $table->unsignedInteger('page_end')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();
            $table->text('unpublish_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->timestamps();
            $table->index(['magazine_issue_id', 'status', 'scheduled_for'], 'publication_issue_schedule_index');
            $table->unique('doi', 'publication_records_doi_unique');
        });

        Schema::create('publication_file_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_record_id')->constrained('publication_records')->cascadeOnDelete();
            $table->foreignId('article_file_id')->constrained('article_files')->restrictOnDelete();
            $table->string('public_role', 40);
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_public')->default(false);
            $table->unsignedTinyInteger('primary_marker')->nullable();
            $table->foreignId('selected_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['publication_record_id', 'article_file_id'], 'publication_file_selection_unique');
            $table->index(['publication_record_id', 'is_primary'], 'publication_primary_file_index');
            $table->unique(['publication_record_id', 'primary_marker'], 'publication_one_primary_file_unique');
        });

        Schema::create('workflow_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->nullable()->constrained('articles')->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('command', 100);
            $table->string('idempotency_key', 100);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamps();
            $table->unique(['actor_id', 'command', 'idempotency_key'], 'workflow_idempotency_scope_unique');
            $table->index(['article_id', 'command', 'created_at'], 'workflow_idempotency_article_index');
        });

        DB::table('articles')->orderBy('id')->chunkById(200, function ($articles) {
            foreach ($articles as $article) {
                $versions = DB::table('article_versions')->where('article_id', $article->id)->orderBy('version_number')->get(['id']);
                $versionId = $versions->last()?->id;
                $acceptedVersionId = DB::table('article_versions')->where('article_id', $article->id)->whereNotNull('accepted_at')->orderByDesc('accepted_at')->value('id');
                DB::table('articles')->where('id', $article->id)->update([
                    'current_version_id' => $versionId,
                    'accepted_version_id' => $acceptedVersionId,
                ]);
                if ($versionId) {
                    DB::table('article_versions')->where('id', $versionId)->whereNull('submitted_at')->update([
                        'submitted_at' => DB::raw('created_at'),
                        'locked_at' => DB::raw('created_at'),
                    ]);
                }
                if ($acceptedVersionId) {
                    DB::table('article_versions')->where('id', $acceptedVersionId)->update(['accepted_marker' => 1]);
                }
                // A single historical version is unambiguous. Multi-version records
                // remain nullable for the diagnostic backfill command to classify;
                // silently attaching them to the latest revision would leak scope.
                if ($versions->count() === 1) {
                    foreach (['sub_editor_assignments', 'reviewer_assignments', 'editorial_decisions'] as $table) {
                        DB::table($table)->where('article_id', $article->id)->whereNull('article_version_id')->update(['article_version_id' => $versionId]);
                    }
                }
                $acceptedSet = DB::table('article_accepted_file_sets')->where('article_id', $article->id)->whereNull('superseded_at')->latest('id')->first();
                if ($acceptedSet) {
                    DB::table('article_accepted_file_sets')->where('id', $acceptedSet->id)->update(['active_marker' => 1]);
                    DB::table('articles')->where('id', $article->id)->update(['accepted_version_id' => $acceptedSet->article_version_id]);
                    DB::table('article_versions')->where('article_id', $article->id)->update(['accepted_marker' => null]);
                    DB::table('article_versions')->where('id', $acceptedSet->article_version_id)->update(['accepted_marker' => 1]);
                    DB::table('production_assignments')->where('article_id', $article->id)->whereNull('article_version_id')->update([
                        'article_version_id' => $acceptedSet->article_version_id,
                        'accepted_file_set_id' => $acceptedSet->id,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_idempotency_keys');
        Schema::dropIfExists('publication_file_selections');
        Schema::dropIfExists('publication_records');
        Schema::dropIfExists('proof_rounds');

        Schema::table('article_accepted_file_sets', function (Blueprint $table) {
            $table->dropUnique('accepted_file_sets_one_active_unique');
            $table->dropColumn('active_marker');
        });

        Schema::table('production_assignments', function (Blueprint $table) {
            $table->dropUnique('production_idempotency_unique');
            $table->dropIndex('production_user_queue_index');
            $table->dropForeign(['article_version_id']);
            $table->dropForeign(['accepted_file_set_id']);
            $table->dropColumn(['article_version_id', 'accepted_file_set_id', 'revoked_at', 'notes', 'idempotency_key']);
            $table->unique(['article_id', 'user_id', 'role']);
        });
        Schema::table('editorial_decisions', function (Blueprint $table) {
            $table->dropUnique('editorial_decision_idempotency_unique');
            $table->dropIndex('editorial_version_round_index');
            $table->dropForeign(['article_version_id']);
            $table->dropForeign(['corrects_decision_id']);
            $table->dropColumn(['article_version_id', 'round_number', 'revision_due_at', 'idempotency_key', 'corrects_decision_id']);
        });
        Schema::table('reviewer_assignments', function (Blueprint $table) {
            $table->dropUnique('reviewer_idempotency_unique');
            $table->dropIndex('reviewer_version_round_status_index');
            $table->dropIndex('reviewer_invitation_expiry_index');
            $table->dropForeign(['article_version_id']);
            $table->dropForeign(['reopened_by']);
            $table->dropColumn(['article_version_id', 'round_number', 'reopened_at', 'reopened_by', 'revoked_at', 'reminder_count', 'last_reminded_at', 'idempotency_key']);
            $table->unique(['article_id', 'reviewer_id']);
        });
        Schema::table('sub_editor_assignments', function (Blueprint $table) {
            $table->dropUnique('sub_editor_idempotency_unique');
            $table->dropIndex('sub_editor_version_status_index');
            $table->dropForeign(['article_version_id']);
            $table->dropForeign(['superseded_by_id']);
            $table->dropColumn(['article_version_id', 'round_number', 'author_comments', 'internal_comments', 'accepted_at', 'declined_at', 'revoked_at', 'superseded_by_id', 'idempotency_key']);
            $table->unique(['article_id', 'sub_editor_id']);
        });
        Schema::table('article_versions', function (Blueprint $table) {
            $table->dropUnique('article_versions_one_accepted_unique');
            $table->dropIndex('article_versions_screening_index');
            $table->dropForeign(['parent_version_id']);
            $table->dropForeign(['screened_by']);
            $table->dropColumn(['parent_version_id', 'screening_status', 'screened_at', 'screened_by', 'submitted_at', 'locked_at', 'accepted_marker']);
        });
        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex('articles_lifecycle_dashboard_index');
            $table->dropIndex('articles_lifecycle_status_index');
            $table->dropForeign(['current_version_id']);
            $table->dropForeign(['accepted_version_id']);
            $table->dropColumn(['lifecycle_status', 'current_version_id', 'accepted_version_id', 'lifecycle_sequence']);
        });
    }
};
