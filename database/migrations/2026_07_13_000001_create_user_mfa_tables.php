<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_mfa_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('method', 20);
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->text('secret_encrypted')->nullable();
            $table->text('pending_secret_encrypted')->nullable();
            $table->timestamp('pending_expires_at')->nullable();
            $table->unsignedTinyInteger('pending_attempts')->default(0);
            $table->json('metadata_json')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'method']);
        });

        Schema::create('user_mfa_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->string('default_method', 20)->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mfa_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('method_requested', 20)->nullable();
            $table->string('email_code_hash')->nullable();
            $table->timestamp('email_code_sent_at')->nullable();
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
        });

        Schema::create('user_mfa_recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_mfa_recovery_codes');
        Schema::dropIfExists('mfa_challenges');
        Schema::dropIfExists('user_mfa_settings');
        Schema::dropIfExists('user_mfa_methods');
    }
};
