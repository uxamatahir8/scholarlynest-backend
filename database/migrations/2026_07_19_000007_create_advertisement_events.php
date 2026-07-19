<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('advertisement_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advertisement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('publication_id')->constrained('magazines')->cascadeOnDelete();
            $table->string('event_type', 20);
            $table->string('placement', 40);
            $table->string('sidebar_side', 10)->nullable();
            $table->char('session_hash', 64);
            $table->timestamps();
            $table->unique(['advertisement_id', 'article_id', 'event_type', 'session_hash'], 'advertisement_event_session_unique');
        });
    }

    public function down(): void { Schema::dropIfExists('advertisement_events'); }
};
