<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('magazine_pages', function (Blueprint $table) {
            if (!Schema::hasColumn('magazine_pages', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('sort_order')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('magazine_pages', 'created_by_role')) {
                $table->string('created_by_role')->nullable()->after('created_by');
            }
            if (!Schema::hasColumn('magazine_pages', 'is_editor_created')) {
                $table->boolean('is_editor_created')->default(false)->after('created_by_role');
            }
        });
    }

    public function down(): void
    {
        Schema::table('magazine_pages', function (Blueprint $table) {
            if (Schema::hasColumn('magazine_pages', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
            if (Schema::hasColumn('magazine_pages', 'created_by_role')) {
                $table->dropColumn('created_by_role');
            }
            if (Schema::hasColumn('magazine_pages', 'is_editor_created')) {
                $table->dropColumn('is_editor_created');
            }
        });
    }
};
