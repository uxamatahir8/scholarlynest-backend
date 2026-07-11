<?php

namespace App\Http\Controllers;

use App\Constants\ArticleStatus;
use App\Models\ArticleFile;
use App\Models\Article;
use App\Models\ArticleAsset;
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
        return response()->json([
            'message' => 'Raw browser uploads are disabled for article assets. Use the direct S3 upload-session flow.',
        ], 410);
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
            if ($user->hasRole('copy_editor')) {
                $sourceFile = \App\Models\ArticleFile::where('source_asset_id', $asset->id)->first();
                if (!$sourceFile || !app(ArticleFileController::class)->canAccess($user, $sourceFile)) {
                    return response()->json(['message' => 'This action is unauthorized.'], 403);
                }
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

             if ($request->has('stream')) {
                 return response()->streamDownload(function () use ($asset, $key) {
                     $stream = Storage::disk($asset->disk)->readStream($key);
                     if ($stream) {
                         fpassthru($stream);
                         fclose($stream);
                     }
                 }, $asset->safe_original_filename ?: $asset->original_filename, [
                     'Content-Type' => $asset->mime_type ?: 'application/octet-stream',
                     'X-Content-Type-Options' => 'nosniff',
                 ]);
             }

             return redirect()->away(
                 Storage::disk($asset->disk)->temporaryUrl($key, now()->addMinutes(config('media_uploads.download_url_ttl_minutes')), [
                     'ResponseContentDisposition' => 'attachment; filename="' . addslashes($asset->safe_original_filename ?: $asset->original_filename) . '"',
                     'ResponseContentType' => $asset->mime_type ?: 'application/octet-stream',
                 ])
             );
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
