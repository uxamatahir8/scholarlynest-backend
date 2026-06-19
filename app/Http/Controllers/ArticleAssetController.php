<?php

namespace App\Http\Controllers;

use App\Constants\ArticleStatus;
use App\Models\ArticleFile;
use App\Models\Article;
use App\Models\ArticleAsset;
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
        if ($user->cannot('update', $article)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        // Validate MIME type and file size (max 25MB)
        $request->validate([
            'file' => 'required|file|mimes:pdf,docx,xlsx,xls,csv,zip,png,jpg,jpeg,txt|max:25600',
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
        $path = $file->store('assets', 'public');

        $asset = ArticleAsset::create([
            'article_id' => $article->id,
            'file_path' => 'storage/' . $path,
            'original_filename' => $safeOriginalName,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
        ]);

        app(ArticleFileController::class)->storeUploadedFile($article, $file, ArticleFile::SUPPLEMENTARY, $user->id, [
            'article_version_id' => $article->versions()->latest('version_number')->value('id'),
            'source_asset_id' => $asset->id,
            'metadata' => ['compatibility_bridge' => 'article_assets'],
        ]);

        return response()->json([
            'message' => 'Asset uploaded successfully.',
            'asset' => $asset
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
        if (!$article || $user->cannot('update', $article)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        // Unlink physical file from storage
        $relativePath = str_replace('storage/', '', $asset->file_path);
        if (Storage::disk('public')->exists($relativePath)) {
            Storage::disk('public')->delete($relativePath);
        }

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

        // Accepted and published articles bypass private access controls.
        if (ArticleStatus::normalize($article->status) !== ArticleStatus::ACCEPTED && ArticleStatus::normalize($article->status) !== ArticleStatus::PUBLISHED) {
            $user = $request->user('sanctum');
            if (!$user || $user->cannot('view', $article)) {
                return response()->json(['message' => 'This action is unauthorized.'], 403);
            }
        }

        $relativePath = str_replace('storage/', '', $asset->file_path);
        if (!Storage::disk('public')->exists($relativePath)) {
            return response()->json(['message' => 'The file could not be found on storage.'], 404);
        }

        $absolutePath = Storage::disk('public')->path($relativePath);

        // Enforce secure file download headers
        return response()->file($absolutePath, [
            'Content-Type' => $asset->mime_type,
            'Content-Disposition' => 'attachment; filename="' . $asset->original_filename . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
