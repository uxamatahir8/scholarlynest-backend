<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cms_pages', function (Blueprint $slugTable) {
            $slugTable->id();
            $slugTable->string('slug')->unique();
            $slugTable->string('title');
            $slugTable->longText('content_text');
            $slugTable->longText('content_html');
            $slugTable->boolean('is_active')->default(true);
            $slugTable->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cms_pages');
    }
};
