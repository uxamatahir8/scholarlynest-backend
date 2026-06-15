<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            if (!Schema::hasColumn('articles', 'article_category')) {
                $table->string('article_category')->nullable()->after('keywords');
            }
            if (!Schema::hasColumn('articles', 'article_type')) {
                $table->string('article_type')->nullable()->after('article_category');
            }
            if (!Schema::hasColumn('articles', 'subject_area')) {
                $table->string('subject_area')->nullable()->after('article_type');
            }
            if (!Schema::hasColumn('articles', 'language')) {
                $table->string('language', 32)->nullable()->after('subject_area');
            }
            if (!Schema::hasColumn('articles', 'ethical_approval_statement')) {
                $table->text('ethical_approval_statement')->nullable()->after('language');
            }
            if (!Schema::hasColumn('articles', 'conflict_of_interest_statement')) {
                $table->text('conflict_of_interest_statement')->nullable()->after('ethical_approval_statement');
            }
            if (!Schema::hasColumn('articles', 'funding_statement')) {
                $table->text('funding_statement')->nullable()->after('conflict_of_interest_statement');
            }
            if (!Schema::hasColumn('articles', 'data_availability_statement')) {
                $table->text('data_availability_statement')->nullable()->after('funding_statement');
            }
            if (!Schema::hasColumn('articles', 'author_contribution_statement')) {
                $table->text('author_contribution_statement')->nullable()->after('data_availability_statement');
            }
        });

        Schema::table('article_author', function (Blueprint $table) {
            if (!Schema::hasColumn('article_author', 'author_order')) {
                $table->unsignedInteger('author_order')->default(1)->after('orcid');
            }
            if (!Schema::hasColumn('article_author', 'contribution_statement')) {
                $table->text('contribution_statement')->nullable()->after('is_corresponding');
            }
        });
    }

    public function down(): void
    {
        Schema::table('article_author', function (Blueprint $table) {
            foreach (['author_order', 'contribution_statement'] as $column) {
                if (Schema::hasColumn('article_author', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('articles', function (Blueprint $table) {
            foreach ([
                'article_category',
                'article_type',
                'subject_area',
                'language',
                'ethical_approval_statement',
                'conflict_of_interest_statement',
                'funding_statement',
                'data_availability_statement',
                'author_contribution_statement',
            ] as $column) {
                if (Schema::hasColumn('articles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
