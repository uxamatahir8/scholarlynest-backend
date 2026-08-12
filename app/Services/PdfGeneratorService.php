<?php

namespace App\Services;

use App\Models\Article;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Services\Media\MediaStorageService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PdfGeneratorService
{
    /**
     * Generate a clean academic PDF for the given article and save it to public storage.
     */
    public function generate(Article $article): string
    {
        // 1. Prepare academic PDF HTML template with clean styling
        $html = $this->compileHtml($article);

        // 2. Generate PDF using DomPDF
        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'portrait');

        // 3. Define path and store the PDF file on the configured media disk
        $fileName = 'articles/scholarlynest_article_' . $article->id . '_' . Str::slug($article->title) . '.pdf';

        return app(MediaStorageService::class)->put($fileName, $pdf->output());
    }

    /**
     * Compile HTML structure with academic styles.
     */
    private function compileHtml(Article $article): string
    {
        $article->loadMissing(['user', 'publicationSections']);
        $authorName = e($article->user->name);
        $authorEmail = e($article->user->email);
        $title = e($article->title);
        $date = ($article->published_at ?: $article->created_at)->format('F d, Y');
        $sections = $article->publicationSections->sortBy('sort_order');
        if ($sections->isEmpty()) {
            $sections = collect([
                (object) ['title' => 'Abstract', 'content_html' => $article->abstract, 'media_upload_session_id' => null],
                (object) ['title' => 'Article', 'content_html' => $article->full_text, 'media_upload_session_id' => null],
            ]);
        }
        $sectionHtml = $sections->map(function ($section) {
            $imageHtml = '';
            if (!empty($section->media_upload_session_id)) {
                $upload = \App\Models\MediaUploadSession::find($section->media_upload_session_id);
                $disk = $upload?->disk ?: config('media_uploads.disk', 's3');
                if ($upload?->s3_clean_key && Storage::disk($disk)->exists($upload->s3_clean_key)) {
                    $mime = $upload->detected_mime_type ?: $upload->declared_mime_type ?: 'image/jpeg';
                    $imageHtml = '<img class="section-image" src="data:' . e($mime) . ';base64,'
                        . base64_encode(Storage::disk($disk)->get($upload->s3_clean_key)) . '" alt="' . e($section->title) . '">';
                }
            }

            return '<section class="content-section"><h1>' . e($section->title) . '</h1>'
                . $imageHtml . ($section->content_html ?: '') . '</section>';
        })->implode('');
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\"/>
            <title>{$title}</title>
            <style>
                @page {
                    margin: 2.5cm 2.5cm 2.5cm 2.5cm;
                }
                body {
                    font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
                    font-size: 11pt;
                    line-height: 1.6;
                    color: #2D3748;
                }
                .header {
                    border-bottom: 2px solid #1E3A8A;
                    padding-bottom: 15px;
                    margin-bottom: 30px;
                }
                                .magazine-title {
                    font-size: 9pt;
                    font-weight: bold;
                    text-transform: uppercase;
                    letter-spacing: 0.1em;
                    color: #D4AF37; /* Gold accent */
                    margin-bottom: 5px;
                }
                .article-title {
                    font-size: 22pt;
                    font-weight: bold;
                    color: #1E3A8A; /* Accent color */
                    line-height: 1.2;
                    margin: 10px 0;
                }
                .author-meta {
                    font-size: 10pt;
                    color: #4A5568;
                    margin-top: 15px;
                }
                .author-name {
                    font-weight: bold;
                }
                .date {
                    font-size: 9pt;
                    color: #718096;
                }
                .abstract-container {
                    background-color: #F7FAFC;
                    border: 1px solid #E2E8F0;
                    border-left: 4px solid #D4AF37;
                    padding: 15px 20px;
                    margin-bottom: 30px;
                }
                .abstract-title {
                    font-size: 11pt;
                    font-weight: bold;
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                    color: #1E3A8A;
                    margin-top: 0;
                    margin-bottom: 8px;
                }
                .content-section {
                    margin-top: 25px;
                }
                .footer {
                    position: fixed;
                    bottom: -1cm;
                    left: 0px;
                    right: 0px;
                    height: 1cm;
                    font-size: 8pt;
                    color: #A0AEC0;
                    border-top: 1px solid #E2E8F0;
                    padding-top: 5px;
                    text-align: center;
                }
                h1, h2, h3 {
                    color: #1E3A8A;
                    font-weight: bold;
                }
                h1 { font-size: 14pt; margin-top: 30px; border-bottom: 1px solid #E2E8F0; padding-bottom: 5px; }
                h2 { font-size: 12pt; margin-top: 25px; }
                p { margin-bottom: 15px; text-align: justify; }
                .section-image { display: block; max-width: 100%; max-height: 420px; margin: 14px auto 20px; object-fit: contain; }
            </style>
        </head>
        <body>
            <div class=\"header\">
                <div class=\"magazine-title\">ScholarlyNest Magazine Archive</div>
                <div class=\"article-title\">{$title}</div>
                <div class=\"author-meta\">
                    <span class=\"author-name\">{$authorName}</span><br/>
                    <span class=\"author-email\">{$authorEmail}</span>
                </div>
                <div class=\"date\">Published: {$date}</div>
            </div>

            {$sectionHtml}

            <div class=\"footer\">
                ScholarlyNest Dynamic Publishing System • dev.scholarlynest.com
            </div>
        </body>
        </html>
        ";
    }
}
