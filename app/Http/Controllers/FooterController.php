<?php

namespace App\Http\Controllers;

use App\Models\FooterCategory;
use App\Models\FooterPage;
use Illuminate\Http\JsonResponse;

class FooterController extends Controller
{
    /**
     * Get all visible categories and pages for the global footer (Public Endpoint).
     */
    public function index(): JsonResponse
    {
        $categories = FooterCategory::with(['pages' => function ($query) {
                $query->where('is_visible', true)->orderBy('sort_order', 'asc');
            }])
            ->orderBy('sort_order', 'asc')
            ->get();

        return response()->json($categories);
    }

    /**
     * Get a specific custom page by its slug (Public Endpoint).
     */
    public function showPage(string $slug): JsonResponse
    {
        $page = FooterPage::where('slug', $slug)
            ->where('is_visible', true)
            ->first();

        if (!$page) {
            return response()->json([
                'message' => 'Page not found.'
            ], 404);
        }

        return response()->json($page);
    }
}
