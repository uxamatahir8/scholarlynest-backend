<?php

namespace App\Http\Controllers;

use App\Models\CmsPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CmsPageController extends Controller
{
    /**
     * Get the dynamic CMS page details by slug (Public Endpoint).
     */
    public function show(string $slug): JsonResponse
    {
        $page = CmsPage::where('slug', $slug)
            ->where('is_active', true)
            ->first();

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
        // 1. Strict RBAC Enforcement (Only admin / super admin)
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin'))) {
            return response()->json([
                'message' => 'Unauthorized. Super Admin or Admin role privileges are required.'
            ], 403);
        }

        // 2. Strict Input Validation
        $validatedData = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'content_html' => 'required|string',
            'content_text' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $page = CmsPage::where('slug', $slug)->first();
        if (!$page) {
            return response()->json([
                'message' => 'The targeted CMS page does not exist.'
            ], 404);
        }

        // 3. Automated HTML to Plain-Text Conversion Fallback
        $htmlContent = $validatedData['content_html'];
        
        // If content_text is omitted or empty, generate a clean text version using strip_tags
        $textContent = $validatedData['content_text'] 
            ?? trim(preg_replace('/\s+/', ' ', strip_tags($htmlContent)));

        // 4. Secure HTML Sanitization (Ensure no inline script tags are stored to avoid XSS)
        // We strip <script> blocks completely for robust security.
        $sanitizedHtml = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $htmlContent);

        // Update record
        $page->update([
            'title' => $validatedData['title'] ?? $page->title,
            'content_html' => $sanitizedHtml,
            'content_text' => $textContent,
            'is_active' => $validatedData['is_active'] ?? $page->is_active,
        ]);

        Log::info("CMS Page '{$slug}' updated by User ID: {$user->id} ({$user->email})");

        return response()->json([
            'message' => 'CMS Page Content updated successfully.',
            'page' => $page
        ]);
    }
}
