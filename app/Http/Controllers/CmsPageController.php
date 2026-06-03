<?php

namespace App\Http\Controllers;

use App\Models\CmsPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CmsPageController extends Controller
{
    /**
     * Get the dynamic CMS page details by slug (Public Endpoint).
     */
    public function show(string $slug): JsonResponse
    {
        $cacheKey = "cms_page_{$slug}";
        $page = null;

        try {
            $page = Cache::tags(['cms_pages'])->remember($cacheKey, 3600, function () use ($slug) {
                return CmsPage::where('slug', $slug)
                    ->where('is_active', true)
                    ->first();
            });
        } catch (\BadMethodCallException $e) {
            $page = Cache::remember($cacheKey, 3600, function () use ($slug) {
                return CmsPage::where('slug', $slug)
                    ->where('is_active', true)
                    ->first();
            });
        }

        if (!$page) {
            return response()->json([
                'message' => 'Requested page content could not be located.'
            ], 404);
        }

        return response()->json($page);
    }

    /**
     * Update the CMS page details (Admin Authorized Endpoint).
     */
    public function update(Request $request, string $slug): JsonResponse
    {
        // 1. Strict RBAC Enforcement (Only admin / super admin / settings managers)
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin') && !$user->hasPermission('settings.manage'))) {
            return response()->json([
                'message' => 'Unauthorized. Super Admin or Admin role privileges are required.'
            ], 403);
        }

        // 2. Strict Input Validation
        $validatedData = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'content_html' => 'sometimes|required|string',
            'content' => 'sometimes|required|string',
            'content_text' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $htmlContent = $validatedData['content_html'] ?? $validatedData['content'] ?? null;

        if ($htmlContent === null) {
            return response()->json([
                'message' => 'The content_html or content field is required.'
            ], 422);
        }

        // 3. Automated HTML to Plain-Text Conversion Fallback
        $textContent = $validatedData['content_text'] 
            ?? trim(preg_replace('/\s+/', ' ', strip_tags($htmlContent)));

        // 4. Secure HTML Sanitization (Ensure no inline script tags are stored to avoid XSS)
        // We strip <script> blocks completely for robust security.
        $sanitizedHtml = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $htmlContent);

        // 5. Upsert or update record
        $page = CmsPage::updateOrCreate(
            ['slug' => $slug],
            [
                'title' => $validatedData['title'] ?? ($slug === 'editorial-board' ? 'Editorial Board' : ucfirst($slug)),
                'content_html' => $sanitizedHtml,
                'content_text' => $textContent,
                'is_active' => $validatedData['is_active'] ?? true,
            ]
        );

        // 6. Flush Cache
        $cacheKey = "cms_page_{$slug}";
        try {
            Cache::tags(['cms_pages'])->flush();
        } catch (\BadMethodCallException $e) {
            Cache::forget($cacheKey);
        }

        Log::info("CMS Page '{$slug}' updated/created by User ID: {$user->id} ({$user->email})");

        return response()->json([
            'message' => 'CMS Page Content updated successfully.',
            'page' => $page
        ]);
    }
}
