<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Language;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LanguageController extends Controller
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
        $query = Language::query();

        if ($request->has('active_only')) {
            $query->where('is_active', true);
        }

        $languages = $query->orderBy('name', 'asc')->get();

        return response()->json($languages);
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
            'name' => 'required|string|max:255|unique:languages,name',
            'code' => 'nullable|string|max:10|unique:languages,code',
            'is_active' => 'sometimes|boolean',
        ]);

        $language = Language::create($validatedData);

        Log::info("Language ID {$language->id} created by User ID: {$request->user()->id}");

        return response()->json([
            'message' => 'Language created successfully.',
            'data' => $language
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

        $language = Language::find($id);
        if (!$language) {
            return response()->json(['message' => 'Language not found.'], 404);
        }

        $validatedData = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:languages,name,' . $id,
            'code' => 'nullable|string|max:10|unique:languages,code,' . $id,
            'is_active' => 'sometimes|boolean',
        ]);

        $language->update($validatedData);

        Log::info("Language ID {$language->id} updated by User ID: {$request->user()->id}");

        return response()->json([
            'message' => 'Language updated successfully.',
            'data' => $language
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

        $language = Language::find($id);
        if (!$language) {
            return response()->json(['message' => 'Language not found.'], 404);
        }

        $language->delete();

        Log::info("Language ID {$id} deleted by User ID: {$request->user()->id}");

        return response()->json([
            'message' => 'Language deleted successfully.'
        ]);
    }
}
