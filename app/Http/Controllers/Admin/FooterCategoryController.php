<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FooterCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FooterCategoryController extends Controller
{
    /**
     * Helper to enforce settings.manage permission or admin/super_admin roles.
     */
    protected function checkAdminPermission(Request $request)
    {
        $user = $request->user();
        return $user && ($user->hasRole('super_admin') || $user->hasRole('admin') || $user->hasPermission('footer.manage') || $user->hasPermission('settings.manage'));
    }

    /**
     * Display a listing of the resource (Admin Authorized).
     */
    public function index(Request $request): JsonResponse
    {
        if (!$this->checkAdminPermission($request)) {
            return response()->json(['message' => 'Unauthorized. Admin privileges are required.'], 403);
        }

        $categories = FooterCategory::with('pages')
            ->orderBy('sort_order', 'asc')
            ->get();

        return response()->json($categories);
    }

    /**
     * Store a newly created resource in storage (Admin Authorized).
     */
    public function store(Request $request): JsonResponse
    {
        if (!$this->checkAdminPermission($request)) {
            return response()->json(['message' => 'Unauthorized. Admin privileges are required.'], 403);
        }

        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'sort_order' => 'sometimes|integer',
        ]);

        $category = FooterCategory::create([
            'name' => $validatedData['name'],
            'sort_order' => $validatedData['sort_order'] ?? 0,
        ]);

        Cache::forget('public_footer_menu');
        Log::info("Footer Category ID {$category->id} created by User ID: {$request->user()->id}");

        return response()->json([
            'message' => 'Category created successfully.',
            'category' => $category
        ], 201);
    }

    /**
     * Update the specified resource in storage (Admin Authorized).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->checkAdminPermission($request)) {
            return response()->json(['message' => 'Unauthorized. Admin privileges are required.'], 403);
        }

        $category = FooterCategory::find($id);
        if (!$category) {
            return response()->json(['message' => 'Category not found.'], 404);
        }

        $validatedData = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'sort_order' => 'sometimes|integer',
        ]);

        $category->update($validatedData);

        Cache::forget('public_footer_menu');
        Log::info("Footer Category ID {$category->id} updated by User ID: {$request->user()->id}");

        return response()->json([
            'message' => 'Category updated successfully.',
            'category' => $category
        ]);
    }

    /**
     * Remove the specified resource from storage (Admin Authorized).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (!$this->checkAdminPermission($request)) {
            return response()->json(['message' => 'Unauthorized. Admin privileges are required.'], 403);
        }

        $category = FooterCategory::find($id);
        if (!$category) {
            return response()->json(['message' => 'Category not found.'], 404);
        }

        $category->delete();

        Cache::forget('public_footer_menu');
        Log::info("Footer Category ID {$id} deleted by User ID: {$request->user()->id}");

        return response()->json([
            'message' => 'Category deleted successfully.'
        ]);
    }
}
