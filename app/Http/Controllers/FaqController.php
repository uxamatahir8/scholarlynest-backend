<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FaqController extends Controller
{
    /**
     * Get safe public FAQs for anonymous visitors.
     */
    public function publicIndex(): JsonResponse
    {
        $faqs = Faq::where('is_active', true)
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get(['id', 'question', 'answer', 'sort_order']);

        return response()->json([
            'data' => $faqs,
        ]);
    }

    /**
     * Get all FAQs (Public Endpoint).
     */
    public function index(): JsonResponse
    {
        $faqs = Faq::where('is_active', true)
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json($faqs);
    }

    /**
     * Get all FAQs for admin dashboard (including inactive).
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin') && !$user->hasPermission('settings.manage'))) {
            return response()->json([
                'message' => 'Unauthorized. Admin privileges are required.'
            ], 403);
        }

        $faqs = Faq::orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json($faqs);
    }

    /**
     * Store a new FAQ (Admin Authorized).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin') && !$user->hasPermission('settings.manage'))) {
            return response()->json([
                'message' => 'Unauthorized. Admin privileges are required.'
            ], 403);
        }

        $validatedData = $request->validate([
            'question' => 'required|string|max:500',
            'answer' => 'required|string',
            'sort_order' => 'sometimes|integer',
            'is_active' => 'sometimes|boolean',
        ]);

        $faq = Faq::create([
            'question' => $validatedData['question'],
            'answer' => $validatedData['answer'],
            'sort_order' => $validatedData['sort_order'] ?? 0,
            'is_active' => $validatedData['is_active'] ?? true,
        ]);

        Log::info("FAQ ID {$faq->id} created by User ID: {$user->id} ({$user->email})");

        return response()->json([
            'message' => 'FAQ created successfully.',
            'faq' => $faq
        ], 201);
    }

    /**
     * Update an FAQ (Admin Authorized).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin') && !$user->hasPermission('settings.manage'))) {
            return response()->json([
                'message' => 'Unauthorized. Admin privileges are required.'
            ], 403);
        }

        $faq = Faq::find($id);
        if (!$faq) {
            return response()->json(['message' => 'FAQ not found.'], 404);
        }

        $validatedData = $request->validate([
            'question' => 'sometimes|required|string|max:500',
            'answer' => 'sometimes|required|string',
            'sort_order' => 'sometimes|integer',
            'is_active' => 'sometimes|boolean',
        ]);

        $faq->update($validatedData);

        Log::info("FAQ ID {$faq->id} updated by User ID: {$user->id} ({$user->email})");

        return response()->json([
            'message' => 'FAQ updated successfully.',
            'faq' => $faq
        ]);
    }

    /**
     * Delete an FAQ (Admin Authorized).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->hasRole('super_admin') && !$user->hasRole('admin') && !$user->hasPermission('settings.manage'))) {
            return response()->json([
                'message' => 'Unauthorized. Admin privileges are required.'
            ], 403);
        }

        $faq = Faq::find($id);
        if (!$faq) {
            return response()->json(['message' => 'FAQ not found.'], 404);
        }

        $faq->delete();

        Log::info("FAQ ID {$id} deleted by User ID: {$user->id} ({$user->email})");

        return response()->json([
            'message' => 'FAQ deleted successfully.'
        ]);
    }
}
