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

        return response()->json([
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'content_html' => $page->content_html,
            'content_text' => $page->content_text,
            'seo_title' => $page->seo_title ?: $page->title . ' | ScholarlyNest',
            'seo_description' => $page->seo_description ?: \Illuminate\Support\Str::limit(strip_tags($page->content_html), 160, ''),
            'seo_keywords' => $page->seo_keywords ?: '',
            'updated_at' => $page->updated_at,
        ]);
    }

    /**
     * Get a CMS page for authenticated internal management, including inactive pages.
     */
    public function adminShow(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();
        if (!$user || (
            !$user->hasRole('super_admin')
            && !$user->hasRole('admin')
            && !$user->hasPermission('settings.manage')
            && !$user->hasPermission('seo.cms-pages')
        )) {
            return response()->json([
                'message' => 'Unauthorized. CMS management privileges are required.'
            ], 403);
        }

        $page = CmsPage::where('slug', $slug)->first();

        if (!$page) {
            return response()->json([
                'message' => 'Requested page content could not be located.'
            ], 404);
        }

        return response()->json([
            'page' => $this->cmsPagePayload($page),
        ]);
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
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:500',
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

        $updatePayload = [
            'title' => $validatedData['title'] ?? ($slug === 'editorial-board' ? 'Editorial Board' : ucfirst($slug)),
            'content_html' => $sanitizedHtml,
            'content_text' => $textContent,
            'is_active' => $validatedData['is_active'] ?? true,
        ];

        if ($user->hasPermission('seo.cms-pages')) {
            $updatePayload['seo_title'] = $validatedData['seo_title'] ?? null;
            $updatePayload['seo_description'] = $validatedData['seo_description'] ?? null;
            $updatePayload['seo_keywords'] = $validatedData['seo_keywords'] ?? null;
        }

        // 5. Upsert or update record
        $page = CmsPage::updateOrCreate(
            ['slug' => $slug],
            $updatePayload
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
            'page' => $this->cmsPagePayload($page),
        ]);
    }

    /**
     * PATCH /api/admin/cms/{slug}/seo
     * SEO-only update.
     */
    public function updateSeo(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->hasPermission('seo.cms-pages')) {
            return response()->json(['message' => 'Unauthorized. SEO permission required.'], 403);
        }

        $page = CmsPage::where('slug', $slug)->firstOrFail();

        $validated = $request->validate([
            'seo_title'       => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords'    => 'nullable|string|max:500',
        ]);

        $page->update($validated);

        // Flush Cache
        $cacheKey = "cms_page_{$slug}";
        try {
            Cache::tags(['cms_pages'])->flush();
        } catch (\BadMethodCallException $e) {
            Cache::forget($cacheKey);
        }

        return response()->json([
            'message' => 'CMS Page SEO metadata updated successfully.',
            'page' => $this->cmsPagePayload($page),
        ]);
    }

    private function cmsPagePayload(CmsPage $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'content_html' => $page->content_html,
            'content_text' => $page->content_text,
            'is_active' => (bool) $page->is_active,
            'seo_title' => $page->seo_title,
            'seo_description' => $page->seo_description,
            'seo_keywords' => $page->seo_keywords,
            'updated_at' => $page->updated_at,
        ];
    }
}
