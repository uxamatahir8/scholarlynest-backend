<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_questions', function (Blueprint $table) {
            $table->string('comment_helper', 500)->nullable()->after('prompt');
        });

        Schema::table('review_question_responses', function (Blueprint $table) {
            $table->text('comment')->nullable()->after('answer');
        });
    }

    public function down(): void
    {
        Schema::table('review_question_responses', fn (Blueprint $table) => $table->dropColumn('comment'));
        Schema::table('review_questions', fn (Blueprint $table) => $table->dropColumn('comment_helper'));
    }
};
