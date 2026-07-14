<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mfa_challenges', function (Blueprint $table) {
            $table->json('required_methods')->nullable()->after('method_requested');
            $table->json('verified_methods')->nullable()->after('required_methods');
            $table->unsignedTinyInteger('email_attempt_count')->default(0)->after('attempts');
            $table->unsignedTinyInteger('totp_attempt_count')->default(0)->after('email_attempt_count');
            $table->unsignedTinyInteger('recovery_code_attempt_count')->default(0)->after('totp_attempt_count');
            $table->boolean('recovery_code_allowed')->default(false)->after('recovery_code_attempt_count');
        });
    }

    public function down(): void
    {
        Schema::table('mfa_challenges', function (Blueprint $table) {
            $table->dropColumn([
                'required_methods',
                'verified_methods',
                'email_attempt_count',
                'totp_attempt_count',
                'recovery_code_attempt_count',
                'recovery_code_allowed',
            ]);
        });
    }
};
