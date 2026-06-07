<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email')->nullable()->after('email');
            $table->string('email_change_code')->nullable()->after('pending_email');
            $table->timestamp('email_change_code_expires_at')->nullable()->after('email_change_code');
            $table->string('new_email_verification_code')->nullable()->after('email_change_code_expires_at');
            $table->timestamp('new_email_verification_code_expires_at')->nullable()->after('new_email_verification_code');
            $table->boolean('current_email_verified')->default(false)->after('new_email_verification_code_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'pending_email',
                'email_change_code',
                'email_change_code_expires_at',
                'new_email_verification_code',
                'new_email_verification_code_expires_at',
                'current_email_verified'
            ]);
        });
    }
};
