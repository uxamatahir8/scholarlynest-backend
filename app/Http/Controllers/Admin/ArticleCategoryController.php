<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ArticleCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ArticleCategoryController extends Controller
{
    /**
     * Enforce settings.manage permission or admin/super_admin roles for write operations.
     */
    protected function checkAdminPermission(Request $request)
    {
        $user = $request->user();
        return $user && ($user->hasRole('super_admin') || $user->hasRole('admin') || $user->hasPermission('settings.manage'));
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ArticleCategory::query();

        if ($request->has('active_only')) {
            $query->where('is_active', true);
        }

        $categories = $query->orderBy('name', 'asc')->get();

        return response()->json($categories);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$this->checkAdminPermission($request)) {
            return response()->json(['message' => 'Unauthorized. Admin privileges are required.'], 403);
        }

        $validatedData = $request->validate([
            'name' => 'required|string|max:255|unique:article_categories,name',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $category = ArticleCategory::create($validatedData);

        Log::info("Article Category ID {$category->id} created by User ID: {$request->user()->id}");

        return response()->json([
            'message' => 'Category created successfully.',
            'data' => $category
        ], 201);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->checkAdminPermission($request)) {
            return response()->json(['message' => 'Unauthorized. Admin privileges are required.'], 403);
        }

        $category = ArticleCategory::find($id);
        if (!$category) {
            return response()->json(['message' => 'Category not found.'], 404);
        }

        $validatedData = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:article_categories,name,' . $id,
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $category->update($validatedData);

        Log::info("Article Category ID {$category->id} updated by User ID: {$request->user()->id}");

        return response()->json([
            'message' => 'Category updated successfully.',
            'data' => $category
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (!$this->checkAdminPermission($request)) {
            return response()->json(['message' => 'Unauthorized. Admin privileges are required.'], 403);
        }

        $category = ArticleCategory::find($id);
        if (!$category) {
            return response()->json(['message' => 'Category not found.'], 404);
        }

        $category->delete();

        Log::info("Article Category ID {$id} deleted by User ID: {$request->user()->id}");

        return response()->json([
            'message' => 'Category deleted successfully.'
        ]);
    }
}
