<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use App\Models\Magazine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    /**
     * GET /api/admin/tags
     * List all tags or filter by magazine_id.
     */
    public function index(Request $request): JsonResponse
    {
        $magazineId = $request->query('magazine_id');
        $query = Tag::with('magazine:id,title');

        if ($magazineId) {
            $query->where('magazine_id', $magazineId);
        }

        $tags = $query->orderBy('name', 'asc')->get();

        return response()->json($tags);
    }

    /**
     * POST /api/admin/tags
     * Create a new tag (Admin/Editor only).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin') && !$user->hasRole('editor'))) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'magazine_id' => 'required|exists:magazines,id',
            'name' => 'required|string|max:255',
        ]);

        // Enforce uniqueness within the magazine
        $exists = Tag::where('magazine_id', $validated['magazine_id'])
            ->where('name', $validated['name'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Tag already exists in this magazine.'], 422);
        }

        $tag = Tag::create($validated);

        return response()->json([
            'message' => 'Tag created successfully.',
            'tag' => $tag
        ], 211);
    }

    /**
     * PUT /api/admin/tags/{id}
     * Update an existing tag (Admin/Editor only).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin') && !$user->hasRole('editor'))) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $tag = Tag::find($id);
        if (!$tag) {
            return response()->json(['message' => 'Tag not found.'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        // Enforce uniqueness within the same magazine, excluding the current tag
        $exists = Tag::where('magazine_id', $tag->magazine_id)
            ->where('name', $validated['name'])
            ->where('id', '!=', $id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Another tag with this name already exists in this magazine.'], 422);
        }

        $tag->update($validated);

        return response()->json([
            'message' => 'Tag updated successfully.',
            'tag' => $tag
        ]);
    }

    /**
     * DELETE /api/admin/tags/{id}
     * Delete an existing tag (Admin/Editor only).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin') && !$user->hasRole('editor'))) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $tag = Tag::find($id);
        if (!$tag) {
            return response()->json(['message' => 'Tag not found.'], 404);
        }

        $tag->delete();

        return response()->json([
            'message' => 'Tag deleted successfully.'
        ]);
    }
}
