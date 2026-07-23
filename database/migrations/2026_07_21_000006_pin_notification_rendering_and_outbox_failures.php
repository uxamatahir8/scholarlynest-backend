<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropIndex('user_notifications_digest_idx');
            $table->unsignedSmallInteger('template_version')->nullable()->after('privacy_variant');
            $table->string('rendered_title', 500)->nullable()->after('body_key');
            $table->text('rendered_body')->nullable()->after('rendered_title');
            $table->index(
                ['email_mode', 'digest_frequency', 'digest_sent_at', 'created_at', 'id'],
                'user_notifications_digest_idx'
            );
        });

        Schema::table('notification_events', function (Blueprint $table) {
            $table->timestamp('permanently_failed_at', 6)->nullable()->after('processed_at');
            $table->string('failure_code', 80)->nullable()->after('last_error');
            $table->index(['permanently_failed_at', 'available_at', 'id'], 'notification_events_failed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('notification_events', function (Blueprint $table) {
            $table->dropIndex('notification_events_failed_idx');
            $table->dropColumn(['permanently_failed_at', 'failure_code']);
        });

        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropIndex('user_notifications_digest_idx');
            $table->dropColumn(['template_version', 'rendered_title', 'rendered_body']);
            $table->index(
                ['email_mode', 'digest_frequency', 'digest_sent_at'],
                'user_notifications_digest_idx'
            );
        });
    }
};
