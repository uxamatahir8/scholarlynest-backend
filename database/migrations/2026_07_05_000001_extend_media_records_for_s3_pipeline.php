<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_files', function (Blueprint $table) {
            foreach ([
                'disk' => fn () => $table->string('disk')->default('public')->after('visibility'),
                'storage_key' => fn () => $table->string('storage_key')->nullable()->after('file_path'),
                'safe_original_name' => fn () => $table->string('safe_original_name')->nullable()->after('original_name'),
                'checksum_sha256' => fn () => $table->string('checksum_sha256', 64)->nullable()->after('size'),
                'scan_status' => fn () => $table->string('scan_status')->default('clean')->after('checksum_sha256'),
                'scan_engine' => fn () => $table->string('scan_engine')->nullable()->after('scan_status'),
                'scanned_at' => fn () => $table->timestamp('scanned_at')->nullable()->after('scan_engine'),
            ] as $column => $callback) {
                if (!Schema::hasColumn('article_files', $column)) {
                    $callback();
                }
            }
        });

        Schema::table('article_assets', function (Blueprint $table) {
            foreach ([
                'disk' => fn () => $table->string('disk')->default('public')->after('article_id'),
                'storage_key' => fn () => $table->string('storage_key')->nullable()->after('file_path'),
                'safe_original_filename' => fn () => $table->string('safe_original_filename')->nullable()->after('original_filename'),
                'checksum_sha256' => fn () => $table->string('checksum_sha256', 64)->nullable()->after('file_size'),
                'scan_status' => fn () => $table->string('scan_status')->default('clean')->after('checksum_sha256'),
                'scan_engine' => fn () => $table->string('scan_engine')->nullable()->after('scan_status'),
                'scanned_at' => fn () => $table->timestamp('scanned_at')->nullable()->after('scan_engine'),
            ] as $column => $callback) {
                if (!Schema::hasColumn('article_assets', $column)) {
                    $callback();
                }
            }
        });

        Schema::table('media', function (Blueprint $table) {
            foreach ([
                'storage_key' => fn () => $table->string('storage_key')->nullable()->after('url'),
                'safe_original_name' => fn () => $table->string('safe_original_name')->nullable()->after('filename'),
                'checksum_sha256' => fn () => $table->string('checksum_sha256', 64)->nullable()->after('size'),
                'scan_status' => fn () => $table->string('scan_status')->default('clean')->after('checksum_sha256'),
                'scan_engine' => fn () => $table->string('scan_engine')->nullable()->after('scan_status'),
                'scanned_at' => fn () => $table->timestamp('scanned_at')->nullable()->after('scan_engine'),
            ] as $column => $callback) {
                if (!Schema::hasColumn('media', $column)) {
                    $callback();
                }
            }
        });
    }

    public function down(): void
    {
        foreach (['article_files', 'article_assets', 'media'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['scanned_at', 'scan_engine', 'scan_status', 'checksum_sha256', 'safe_original_name', 'safe_original_filename', 'storage_key', 'disk'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
