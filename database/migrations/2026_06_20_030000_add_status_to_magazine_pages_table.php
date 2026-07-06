<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('magazine_pages', function (Blueprint $table) {
            if (!Schema::hasColumn('magazine_pages', 'status')) {
                $table->string('status', 32)->default('active')->after('sort_order')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('magazine_pages', function (Blueprint $table) {
            if (Schema::hasColumn('magazine_pages', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
