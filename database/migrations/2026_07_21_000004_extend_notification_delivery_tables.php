<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->enum('status', ['pending', 'queued', 'sending', 'sent', 'failed', 'suppressed'])->default('pending')->change();
            $table->foreignId('notification_event_id')->nullable()->after('id')->constrained('notification_events')->nullOnDelete();
            $table->ulid('user_notification_id')->nullable()->after('notification_event_id');
            $table->string('channel', 16)->default('email')->after('subject');
            $table->string('purpose', 120)->nullable()->after('channel');
            $table->string('deduplication_key', 191)->nullable()->unique()->after('purpose');
            $table->string('privacy_variant', 40)->nullable()->after('deduplication_key');
            $table->timestamp('queued_at', 6)->nullable();
            $table->timestamp('sending_at', 6)->nullable();
            $table->timestamp('sent_at', 6)->nullable();
            $table->timestamp('failed_at', 6)->nullable();
            $table->string('provider', 80)->nullable();
            $table->string('provider_message_id', 191)->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->string('last_error_summary', 1000)->nullable();
            $table->index(['status', 'created_at'], 'notification_logs_status_created_idx');
            $table->index(['notification_event_id', 'status'], 'notification_logs_event_status_idx');
            $table->foreign('user_notification_id')->references('id')->on('user_notifications')->nullOnDelete();
        });

        Schema::table('workflow_deadline_reminder_logs', function (Blueprint $table) {
            $table->dropUnique('workflow_deadline_unique_reminder');
            $table->foreignId('recipient_user_id')->nullable()->after('assignment_id')->constrained('users')->nullOnDelete();
            $table->string('due_date_version', 64)->nullable()->after('due_date');
            $table->foreignId('notification_event_id')->nullable()->after('due_date_version')->constrained('notification_events')->nullOnDelete();
            $table->foreignId('escalated_to_user_id')->nullable()->after('notification_event_id')->constrained('users')->nullOnDelete();
            $table->string('delivery_status', 24)->default('recorded')->after('escalated_to_user_id');
            $table->unique(['assignment_type', 'assignment_id', 'recipient_user_id', 'reminder_type', 'due_date_version'], 'workflow_deadline_recipient_due_unique');
            $table->index(['due_date', 'reminder_type'], 'workflow_deadline_due_idx');
        });

        // Existing rows predate delivery lifecycle timestamps.
        DB::table('notification_logs')->where('status', 'sent')->update(['sent_at' => DB::raw('updated_at')]);
        DB::table('notification_logs')->where('status', 'failed')->update(['failed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('workflow_deadline_reminder_logs', function (Blueprint $table) {
            $table->dropUnique('workflow_deadline_recipient_due_unique');
            $table->dropIndex('workflow_deadline_due_idx');
            $table->dropConstrainedForeignId('recipient_user_id');
            $table->dropColumn('due_date_version');
            $table->dropConstrainedForeignId('notification_event_id');
            $table->dropConstrainedForeignId('escalated_to_user_id');
            $table->dropColumn('delivery_status');
            $table->unique(['assignment_type', 'assignment_id', 'reminder_type'], 'workflow_deadline_unique_reminder');
        });

        DB::table('notification_logs')->whereIn('status', ['queued', 'sending', 'suppressed'])->update(['status' => 'pending']);
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending')->change();
            $table->dropForeign(['user_notification_id']);
            $table->dropIndex('notification_logs_status_created_idx');
            $table->dropIndex('notification_logs_event_status_idx');
            $table->dropConstrainedForeignId('notification_event_id');
            $table->dropColumn([
                'user_notification_id', 'channel', 'purpose', 'deduplication_key', 'privacy_variant',
                'queued_at', 'sending_at', 'sent_at', 'failed_at', 'provider', 'provider_message_id',
                'last_error_code', 'last_error_summary',
            ]);
        });
    }
};
