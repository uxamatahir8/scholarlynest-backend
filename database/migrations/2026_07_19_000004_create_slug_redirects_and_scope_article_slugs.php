<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $duplicate = DB::table('articles')
            ->select('magazine_id', 'slug', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('magazine_id', 'slug')->having('aggregate', '>', 1)->first();
        if ($duplicate) {
            throw new RuntimeException('Duplicate article slugs exist within a publication. Resolve them before adding the scoped slug constraint.');
        }

        Schema::create('slug_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('old_slug');
            $table->string('new_slug');
            $table->string('parent_type', 40)->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->timestamps();
            $table->unique(['entity_type', 'parent_id', 'old_slug'], 'slug_redirects_lookup_unique');
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->unique(['magazine_id', 'slug'], 'articles_magazine_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropUnique('articles_magazine_slug_unique');
        });
        Schema::dropIfExists('slug_redirects');
    }
};
