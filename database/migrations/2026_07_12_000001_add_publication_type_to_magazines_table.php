<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('magazines', function (Blueprint $table) {
            $table->string('publication_type')->default('magazine')->index();
        });

        DB::table('magazines')->whereNull('publication_type')->update(['publication_type' => 'magazine']);
    }

    public function down(): void
    {
        Schema::table('magazines', function (Blueprint $table) {
            $table->dropIndex(['publication_type']);
            $table->dropColumn('publication_type');
        });
    }
};
