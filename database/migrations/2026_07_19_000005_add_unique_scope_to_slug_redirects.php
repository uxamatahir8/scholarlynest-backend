<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slug_redirects', function (Blueprint $table) {
            $table->string('scope_key')->nullable()->after('id');
        });
        DB::table('slug_redirects')->orderBy('id')->each(function ($redirect) {
            DB::table('slug_redirects')->where('id', $redirect->id)->update([
                'scope_key' => $redirect->entity_type.':'.((int) ($redirect->parent_id ?? 0)).':'.$redirect->old_slug,
            ]);
        });
        Schema::table('slug_redirects', function (Blueprint $table) {
            $table->unique('scope_key');
        });
    }

    public function down(): void
    {
        Schema::table('slug_redirects', function (Blueprint $table) {
            $table->dropUnique(['scope_key']);
            $table->dropColumn('scope_key');
        });
    }
};
