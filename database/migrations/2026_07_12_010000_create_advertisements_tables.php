<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('advertisements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('image_media_id')->constrained('media')->restrictOnDelete();
            $table->string('alt_text')->nullable();
            $table->string('redirect_url', 2048)->nullable();
            $table->string('placement', 40);
            $table->string('status', 20)->default('draft');
            $table->integer('priority')->default(0);
            $table->boolean('open_in_new_tab')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'starts_at', 'ends_at']);
            $table->index(['placement', 'priority']);
        });

        Schema::create('advertisement_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advertisement_id')->constrained()->cascadeOnDelete();
            $table->string('target_area', 30);
            $table->string('target_mode', 30);
            $table->string('publication_type', 20)->nullable();
            $table->foreignId('publication_id')->nullable()->constrained('magazines')->cascadeOnDelete();
            $table->string('page_key')->nullable();
            $table->foreignId('article_id')->nullable()->constrained('articles')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['target_area', 'target_mode']);
            $table->index(['publication_type', 'publication_id', 'page_key'], 'ad_targets_publication_page_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advertisement_targets');
        Schema::dropIfExists('advertisements');
    }
};
