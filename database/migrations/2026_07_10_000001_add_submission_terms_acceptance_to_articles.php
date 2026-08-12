<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable()->after('status');
            $table->foreignId('terms_accepted_by')->nullable()->after('terms_accepted_at')->constrained('users')->nullOnDelete();
            $table->string('terms_acceptance_ip', 45)->nullable()->after('terms_accepted_by');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('terms_accepted_by');
            $table->dropColumn(['terms_accepted_at', 'terms_acceptance_ip']);
        });
    }
};
