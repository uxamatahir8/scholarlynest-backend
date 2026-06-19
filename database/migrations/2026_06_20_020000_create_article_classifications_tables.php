<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('article_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('subject_areas', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 10)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed defaults
        DB::table('article_types')->insert([
            ['name' => 'Research Article', 'description' => 'Original research manuscript.', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Review Article', 'description' => 'Critical evaluation of existing literature.', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Case Study', 'description' => 'Detailed study of a specific subject or case.', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Editorial', 'description' => 'Opinion or commentary piece.', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('article_categories')->insert([
            ['name' => 'Original Research', 'description' => 'Original experimental or theoretical research.', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Review', 'description' => 'Literature review papers.', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Short Communication', 'description' => 'Brief reports of findings.', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Methodology', 'description' => 'Description of new methods or protocols.', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('subject_areas')->insert([
            ['name' => 'Computational Science', 'description' => 'Scientific calculations and algorithms.', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Biomedical Engineering', 'description' => 'Engineering principles in medicine and biology.', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Medicine', 'description' => 'Medical research and practices.', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Social Sciences', 'description' => 'Human society and social relationships.', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Humanities', 'description' => 'Human culture, philosophy, and history.', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('languages')->insert([
            ['name' => 'English', 'code' => 'en', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Spanish', 'code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'French', 'code' => 'fr', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'German', 'code' => 'de', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            // Seed 'en' alias specifically to support seeded article data compatibility
            ['name' => 'en', 'code' => 'en-short', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('article_types');
        Schema::dropIfExists('article_categories');
        Schema::dropIfExists('subject_areas');
        Schema::dropIfExists('languages');
    }
};
