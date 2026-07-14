<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_public_pages', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content');
            $table->string('status')->default('draft');
            $table->string('target_scope');
            $table->boolean('show_in_navigation')->default(true);
            $table->integer('sort_order')->default(0);
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('shared_public_page_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_public_page_id')->constrained('shared_public_pages')->cascadeOnDelete();
            $table->foreignId('publication_id')->constrained('magazines')->cascadeOnDelete();
            $table->string('publication_type');
            $table->timestamps();
            $table->unique(['shared_public_page_id', 'publication_id'], 'shared_page_publication_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_public_page_targets');
        Schema::dropIfExists('shared_public_pages');
    }
};
