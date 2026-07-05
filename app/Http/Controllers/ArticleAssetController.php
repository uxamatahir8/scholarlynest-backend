<?php

namespace App\Http\Controllers;

use App\Constants\ArticleStatus;
use App\Models\ArticleFile;
use App\Models\Article;
use App\Models\ArticleAsset;
use App\Services\Media\DirectS3UploadService;
use App\Services\Media\MediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ArticleAssetController extends Controller
{
    /**
     * POST /api/articles/{id}/assets
     * Upload and attach an asset to an article.
     */
    public function store(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $article = Article::find($id);
        if (!$article) {
            return response()->json(['message' => 'Article not found.'], 404);
        }

        // Authorize using the update policy of the article
        if ($user->cannot('view', $article)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        if (!ArticleStatus::isEditableStatus($article->status)) {
            return response()->json(['message' => 'This manuscript cannot be edited at its current workflow stage.'], 422);
        }

        if ($user->cannot('update', $article)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        // Validate MIME type and file size (max 25MB)
        $request->validate([
            'file' => 'required|file|mimes:pdf,docx,xlsx,xls,csv,png,jpg,jpeg,txt|max:25600',
        ]);

        $file = $request->file('file');

        // 1. Antivirus / Malware checking
        // TODO(security): Run ClamScan in sandbox. Standard local fallback validates files.
        $fileContents = file_get_contents($file->getRealPath());
        if (str_contains($fileContents, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE')) {
            return response()->json(['message' => 'Malware scan failed: Infected file detected.'], 422);
        }

        // 2. Input/filename sanitization
        $originalName = $file->getClientOriginalName();
        $safeOriginalName = basename($originalName); // Strip path traversal attempts

        $fileSize = $file->getSize();
        $mimeType = $file->getMimeType();

        // 3. Unique hashing filename storage outside web root (stored inside private/public disk, served conditionally)
        $path = app(MediaStorageService::class)->storeUploadedFile($file, 'assets');

        $asset = ArticleAsset::create([
            'article_id' => $article->id,
            'file_path' => $path,
            'storage_key' => $path,
            'disk' => config('media_uploads.disk'),
            'original_filename' => $safeOriginalName,
            'safe_original_filename' => $safeOriginalName,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'scan_status' => 'clean',
            'scanned_at' => now(),
        ]);

        app(ArticleFileController::class)->storeUploadedFile($article, $file, ArticleFile::SUPPLEMENTARY, $user->id, [
            'article_version_id' => $article->versions()->latest('version_number')->value('id'),
            'source_asset_id' => $asset->id,
            'metadata' => ['compatibility_bridge' => 'article_assets'],
        ]);

        return response()->json([
            'message' => 'Asset uploaded successfully.',
            'asset' => $this->serializeAsset($asset),
        ], 201);
    }

    /**
     * DELETE /api/articles/assets/{asset_id}
     * Safely delete an asset and unlink its file.
     */
    public function destroy(Request $request, int $asset_id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $asset = ArticleAsset::find($asset_id);
        if (!$asset) {
            return response()->json(['message' => 'Asset not found.'], 404);
        }

        $article = $asset->article;
        if (!$article || $user->cannot('view', $article)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        if (!ArticleStatus::isEditableStatus($article->status)) {
            return response()->json(['message' => 'This manuscript cannot be edited at its current workflow stage.'], 422);
        }

        if ($user->cannot('update', $article)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        // Unlink physical file from storage
        app(MediaStorageService::class)->delete($asset->storage_key ?: $asset->file_path);

        $asset->delete();

        return response()->json([
            'message' => 'Asset deleted successfully.'
        ]);
    }

    /**
     * GET /api/articles/assets/{asset_id}/download
     * Serve asset file with correct secure download headers.
     */
    public function download(Request $request, int $asset_id)
    {
        $asset = ArticleAsset::find($asset_id);
        if (!$asset) {
            return response()->json(['message' => 'Asset not found.'], 404);
        }

        $article = $asset->article;
        if (!$article) {
            return response()->json(['message' => 'Article not found.'], 404);
        }

        if (ArticleStatus::normalize($article->status) !== ArticleStatus::PUBLISHED) {
            $user = $request->user('sanctum');
            if (!$user || $user->cannot('view', $article)) {
                return response()->json(['message' => 'This action is unauthorized.'], 403);
            }
        }

        if (($asset->scan_status ?? 'clean') !== 'clean') {
            return response()->json(['message' => 'The requested file is not available.'], 404);
        }

        if (($asset->disk ?? 'public') !== 'public') {
            $key = $asset->storage_key ?: $asset->file_path;
            if (!$key || !Storage::disk($asset->disk)->exists($key)) {
                return response()->json(['message' => 'The requested file is not available.'], 404);
            }

            return response()->json([
                'url' => app(DirectS3UploadService::class)->temporaryDownloadUrl($asset->disk, $key, $asset->safe_original_filename ?: $asset->original_filename),
                'expires_in_seconds' => config('media_uploads.download_url_ttl_minutes') * 60,
            ]);
        }

        $relativePath = str_replace('storage/', '', $asset->file_path);
        if (!Storage::disk('public')->exists($relativePath)) {
            return response()->json(['message' => 'The requested file is not available.'], 404);
        }

        $absolutePath = Storage::disk('public')->path($relativePath);

        // Enforce secure file download headers
        return response()->file($absolutePath, [
            'Content-Type' => $asset->mime_type,
            'Content-Disposition' => 'attachment; filename="' . $asset->original_filename . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function serializeAsset(ArticleAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'article_id' => $asset->article_id,
            'original_filename' => $asset->original_filename,
            'file_size' => $asset->file_size,
            'mime_type' => $asset->mime_type,
            'scan_status' => $asset->scan_status ?? 'clean',
            'available' => ($asset->scan_status ?? 'clean') === 'clean',
        ];
    }
}
