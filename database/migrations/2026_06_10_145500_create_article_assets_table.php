<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('article_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->onDelete('cascade');
            $table->string('file_path');
            $table->string('original_filename');
            $table->integer('file_size');
            $table->string('mime_type');
            $table->timestamps();
        });

        // Gracefully migrate existing pdf_path fields as initial assets
        try {
            $articles = DB::table('articles')->whereNotNull('pdf_path')->where('pdf_path', '!=', '')->get();
            foreach ($articles as $article) {
                $relativePath = str_replace('storage/', '', $article->pdf_path);
                $size = 0;
                try {
                    if (Storage::disk('public')->exists($relativePath)) {
                        $size = Storage::disk('public')->size($relativePath);
                    }
                } catch (\Exception $e) {
                    // Ignore disk resolution errors in case of missing physical files during tests/seeders
                }

                DB::table('article_assets')->insert([
                    'article_id' => $article->id,
                    'file_path' => $article->pdf_path,
                    'original_filename' => basename($article->pdf_path) ?: 'manuscript.pdf',
                    'file_size' => $size,
                    'mime_type' => 'application/pdf',
                    'created_at' => $article->created_at ?? now(),
                    'updated_at' => $article->updated_at ?? now(),
                ]);
            }
        } catch (\Exception $e) {
            // Prevent halting migration if something fails during data copying
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_assets');
    }
};
