<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_upload_sessions', function (Blueprint $table) {
            $table->uuid('client_upload_id')->nullable()->after('user_id');
            $table->unique(['user_id', 'purpose', 'client_upload_id'], 'media_upload_sessions_client_unique');
        });
    }

    public function down(): void
    {
        Schema::table('media_upload_sessions', function (Blueprint $table) {
            $table->dropUnique('media_upload_sessions_client_unique');
            $table->dropColumn('client_upload_id');
        });
    }
};
