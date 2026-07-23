<?php

namespace App\Http\Controllers;

use App\Services\Media\MediaStorageService;
use Illuminate\Support\Facades\Storage;

class MediaObjectController extends Controller
{
    public function show(string $token, MediaStorageService $mediaStorage)
    {
        $path = $mediaStorage->decodePath($token);
        $resolved = $mediaStorage->resolvePath($path);

        if (!$resolved) {
            return response()->json(['message' => 'The requested file is not available.'], 404);
        }

        if ($resolved['disk'] === 'public') {
            return redirect()->away(Storage::disk('public')->url($resolved['path']));
        }

        return redirect()->away(Storage::disk($resolved['disk'])->temporaryUrl(
            $resolved['path'],
            now()->addMinutes(config('media_uploads.download_url_ttl_minutes', 5))
        ));
    }
}
