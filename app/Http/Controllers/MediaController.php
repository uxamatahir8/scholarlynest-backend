<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaController extends Controller
{
    /**
     * Store an uploaded media file.
     */
    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Raw browser uploads are disabled. Use the media upload-session direct S3 flow.',
        ], 410);
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
