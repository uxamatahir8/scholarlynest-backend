<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('university_name')->nullable()->after('profile_image');
        });

        Schema::table('article_author', function (Blueprint $table) {
            $table->string('university_name')->nullable()->after('account_provisioned');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('university_name');
        });

        Schema::table('article_author', function (Blueprint $table) {
            $table->dropColumn('university_name');
        });
    }
};
