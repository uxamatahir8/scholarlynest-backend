<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FooterPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FooterPageController extends Controller
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

        $pages = FooterPage::with('category')
            ->orderBy('sort_order', 'asc')
            ->get();

        return response()->json($pages);
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
            'footer_category_id' => 'required|exists:footer_categories,id',
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:footer_pages,slug',
            'content' => 'required|string',
            'is_visible' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
        ]);

        $page = FooterPage::create([
            'footer_category_id' => $validatedData['footer_category_id'],
            'title' => $validatedData['title'],
            'slug' => $validatedData['slug'],
            'content' => $validatedData['content'],
            'is_visible' => $validatedData['is_visible'] ?? true,
            'sort_order' => $validatedData['sort_order'] ?? 0,
        ]);

        Log::info("Footer Page ID {$page->id} created by User ID: {$request->user()->id}");

        return response()->json([
            'message' => 'Page created successfully.',
            'page' => $page
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

        $page = FooterPage::find($id);
        if (!$page) {
            return response()->json(['message' => 'Page not found.'], 404);
        }

        $validatedData = $request->validate([
            'footer_category_id' => 'sometimes|required|exists:footer_categories,id',
            'title' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|required|string|max:255|unique:footer_pages,slug,' . $id,
            'content' => 'sometimes|required|string',
            'is_visible' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
        ]);

        $page->update($validatedData);

        Log::info("Footer Page ID {$page->id} updated by User ID: {$request->user()->id}");

        return response()->json([
            'message' => 'Page updated successfully.',
            'page' => $page
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

        $page = FooterPage::find($id);
        if (!$page) {
            return response()->json(['message' => 'Page not found.'], 404);
        }

        $page->delete();

        Log::info("Footer Page ID {$id} deleted by User ID: {$request->user()->id}");

        return response()->json([
            'message' => 'Page deleted successfully.'
        ]);
    }
}
