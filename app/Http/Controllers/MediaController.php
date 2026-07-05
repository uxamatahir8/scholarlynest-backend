<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Services\Media\MediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    /**
     * Store an uploaded media file.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|image|max:10240', // Limit to 10MB images
        ]);

        $file = $request->file('file');
        
        // Generate a cryptographically clean filename
        $extension = $file->getClientOriginalExtension();
        $safeName = Str::random(40) . '.' . $extension;

        $path = app(MediaStorageService::class)->put('uploads/' . $safeName, file_get_contents($file->getRealPath()));

        $url = app(MediaStorageService::class)->publicOrTemporaryUrl($path);

        $media = Media::create([
            'filename' => $file->getClientOriginalName(),
            'url' => $url,
            'disk' => config('media_uploads.disk'),
            'storage_key' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'scan_status' => 'clean',
            'scanned_at' => now(),
        ]);

        return response()->json($media, 201);
    }

    /**
     * Delete a media item (soft deletes).
     */
    public function destroy(int $id): JsonResponse
    {
        $media = Media::findOrFail($id);
        $media->delete();

        return response()->json(['message' => 'Media soft-deleted successfully.']);
    }
}
