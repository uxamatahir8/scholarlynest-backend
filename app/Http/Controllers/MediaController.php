<?php

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

        // Store file inside public disk under uploads folder
        // public disk is configured to map to public/storage
        $path = $file->storeAs('uploads', $safeName, 'public');

        // Capture absolute or local public URL
        $url = Storage::disk('public')->url($path);

        $media = Media::create([
            'filename' => $file->getClientOriginalName(),
            'url' => $url,
            'disk' => 'public',
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
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
