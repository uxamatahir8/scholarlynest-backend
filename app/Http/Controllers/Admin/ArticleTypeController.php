<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ArticleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ArticleTypeController extends Controller
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
        $query = ArticleType::query();

        // If requested for forms, we can filter active ones only
        if ($request->has('active_only')) {
            $query->where('is_active', true);
        }

        $types = $query->orderBy('name', 'asc')->get();

        return response()->json($types);
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
            'name' => 'required|string|max:255|unique:article_types,name',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $type = ArticleType::create($validatedData);

        Log::info("Article Type ID {$type->id} created by User ID: {$request->user()->id}");

        return response()->json([
            'message' => 'Article Type created successfully.',
            'data' => $type
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

        $type = ArticleType::find($id);
        if (!$type) {
            return response()->json(['message' => 'Article Type not found.'], 404);
        }

        $validatedData = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:article_types,name,' . $id,
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $type->update($validatedData);

        Log::info("Article Type ID {$type->id} updated by User ID: {$request->user()->id}");

        return response()->json([
            'message' => 'Article Type updated successfully.',
            'data' => $type
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

        $type = ArticleType::find($id);
        if (!$type) {
            return response()->json(['message' => 'Article Type not found.'], 404);
        }

        $type->delete();

        Log::info("Article Type ID {$id} deleted by User ID: {$request->user()->id}");

        return response()->json([
            'message' => 'Article Type deleted successfully.'
        ]);
    }
}
