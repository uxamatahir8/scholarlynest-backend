<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('advertisements', function (Blueprint $table) {
            $table->string('sidebar_side', 10)->nullable()->after('placement')->index();
        });

        // Sticky article ads historically rendered below the left-hand article TOC.
        DB::table('advertisements')
            ->where('placement', 'sidebar_sticky')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('advertisement_targets')
                ->whereColumn('advertisement_targets.advertisement_id', 'advertisements.id')
                ->where('advertisement_targets.target_area', 'article'))
            ->update(['sidebar_side' => 'left']);
    }

    public function down(): void
    {
        Schema::table('advertisements', fn (Blueprint $table) => $table->dropColumn('sidebar_side'));
    }
};
