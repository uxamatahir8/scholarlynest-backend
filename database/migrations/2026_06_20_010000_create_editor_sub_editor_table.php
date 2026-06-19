<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editor_sub_editor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('editor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sub_editor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['editor_id', 'sub_editor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editor_sub_editor');
    }
};
