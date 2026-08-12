<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('magazine_user')
            ->whereIn('role', ['super_editor', 'magazine_editor', 'journal_editor'])
            ->update(['role' => 'editor']);
    }

    public function down(): void
    {
        // The user's system role remains authoritative, so the prior redundant
        // pivot label cannot be reconstructed safely.
    }
};
