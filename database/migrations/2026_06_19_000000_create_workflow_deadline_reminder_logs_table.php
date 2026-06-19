<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_deadline_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->string('assignment_type');
            $table->unsignedBigInteger('assignment_id');
            $table->string('reminder_type');
            $table->timestamp('due_date');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['assignment_type', 'assignment_id', 'reminder_type'], 'workflow_deadline_unique_reminder');
            $table->index(['article_id', 'reminder_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_deadline_reminder_logs');
    }
};
