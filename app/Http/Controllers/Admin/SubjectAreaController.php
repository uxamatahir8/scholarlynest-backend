<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubjectArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SubjectAreaController extends Controller
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
        $query = SubjectArea::query();

        if ($request->has('active_only')) {
            $query->where('is_active', true);
        }

        $areas = $query->orderBy('name', 'asc')->get();

        return response()->json($areas);
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
            'name' => 'required|string|max:255|unique:subject_areas,name',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $area = SubjectArea::create($validatedData);

        Log::info("Subject Area ID {$area->id} created by User ID: {$request->user()->id}");

        return response()->json([
            'message' => 'Subject Area created successfully.',
            'data' => $area
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

        $area = SubjectArea::find($id);
        if (!$area) {
            return response()->json(['message' => 'Subject Area not found.'], 404);
        }

        $validatedData = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:subject_areas,name,' . $id,
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $area->update($validatedData);

        Log::info("Subject Area ID {$area->id} updated by User ID: {$request->user()->id}");

        return response()->json([
            'message' => 'Subject Area updated successfully.',
            'data' => $area
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

        $area = SubjectArea::find($id);
        if (!$area) {
            return response()->json(['message' => 'Subject Area not found.'], 404);
        }

        $area->delete();

        Log::info("Subject Area ID {$id} deleted by User ID: {$request->user()->id}");

        return response()->json([
            'message' => 'Subject Area deleted successfully.'
        ]);
    }
}
