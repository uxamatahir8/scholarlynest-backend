<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('article_accepted_file_sets')
            ->whereNull('superseded_at')
            ->orderBy('id')
            ->each(function (object $set): void {
                DB::table('article_accepted_file_set_items')
                    ->where('accepted_file_set_id', $set->id)
                    ->where(function ($query) use ($set): void {
                        $query->whereNull('source_version_id')
                            ->orWhere('source_version_id', '!=', $set->article_version_id);
                    })
                    ->delete();

                DB::table('article_accepted_file_sets')
                    ->where('id', $set->id)
                    ->update(['selection_policy' => 'accepted_version_only']);
            });
    }

    public function down(): void
    {
        DB::table('article_accepted_file_sets')
            ->whereNull('superseded_at')
            ->where('selection_policy', 'accepted_version_only')
            ->update(['selection_policy' => 'carry_forward_latest_per_purpose']);
    }
};
