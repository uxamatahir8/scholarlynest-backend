<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_upload_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose');
            $table->nullableMorphs('attachable');
            $table->string('original_filename');
            $table->string('safe_display_filename');
            $table->unsignedBigInteger('expected_size_bytes');
            $table->string('declared_mime_type')->nullable();
            $table->string('expected_checksum_sha256', 64)->nullable();
            $table->string('disk')->default('s3');
            $table->string('s3_incoming_key');
            $table->string('s3_clean_key')->nullable();
            $table->string('s3_upload_id')->nullable();
            $table->string('upload_mode');
            $table->string('status')->default('initiated');
            $table->json('uploaded_part_manifest')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('scan_requested_at')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->string('detected_mime_type')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('scan_engine')->nullable();
            $table->string('scan_status')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['purpose', 'status']);
            $table->unique('s3_incoming_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_upload_sessions');
    }
};
