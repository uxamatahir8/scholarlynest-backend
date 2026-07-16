<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('reviewer_assignments', 'scorecard')) {
            Schema::table('reviewer_assignments', function (Blueprint $table) {
                $table->dropColumn('scorecard');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('reviewer_assignments', 'scorecard')) {
            Schema::table('reviewer_assignments', function (Blueprint $table) {
                $table->json('scorecard')->nullable();
            });
        }
    }
};
