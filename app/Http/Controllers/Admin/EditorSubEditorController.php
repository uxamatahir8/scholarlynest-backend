<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\PasswordSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EditorSubEditorController extends Controller
{
    public function __construct(private readonly PasswordSetupService $passwordSetupService)
    {
    }

    /**
     * Display a listing of Sub Editors assigned to the authenticated Editor.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasRole('editor')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $subEditors = $user->assignedSubEditors;

        $data = $subEditors->map(function ($subEditor) {
            return [
                'id' => $subEditor->id,
                'name' => $subEditor->name,
                'email' => $subEditor->email,
                'created_at' => $subEditor->created_at,
                'assigned_at' => ($subEditor->pivot && isset($subEditor->pivot->created_at)) ? $subEditor->pivot->created_at : $subEditor->created_at,
                'editors_count' => $subEditor->assignedEditors()->count(),
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Store a new Sub Editor and associate them with the authenticated Editor.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasRole('editor')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
        ]);

        $role = Role::where('name', 'sub_editor')->orWhere('name', 'sub-editor')->first();
        if (!$role) {
            return response()->json(['message' => 'Sub Editor role not found in system.'], 500);
        }

        try {
            $createdUser = false;
            $result = DB::transaction(function () use ($validated, $role, $user, &$createdUser) {
                $subEditor = User::where('email', $validated['email'])->first();

                if (!$subEditor) {
                    $subEditor = User::create([
                        'name' => $validated['name'],
                        'email' => strtolower($validated['email']),
                        'password' => null,
                        'role_id' => $role->id,
                        'email_verified_at' => now(),
                        'needs_password_reset' => true,
                        'university_name' => $user->university_name,
                        'current_email_verified' => true,
                    ]);
                    $createdUser = true;
                } else {
                    $subEditor->role_id = $role->id;
                    $subEditor->save();
                }

                if ($user->hasRole('editor')) {
                    if ($user->assignedSubEditors()->where('sub_editor_id', $subEditor->id)->exists()) {
                        return [
                            'status' => 200,
                            'message' => 'Sub Editor is already assigned to you.',
                            'sub_editor' => $subEditor
                        ];
                    }
                    $user->assignedSubEditors()->attach($subEditor->id, ['created_by' => $user->id]);
                }

                if ($subEditor->assignedEditors()->count() === 0) {
                    throw new \Exception('This Sub Editor must remain assigned to at least one Editor.', 422);
                }

                return [
                    'status' => 201,
                    'message' => 'Sub Editor linked successfully.',
                    'sub_editor' => $subEditor
                ];
            });

            if ($createdUser) {
                $this->passwordSetupService->sendSetupLink($result['sub_editor']);
            }

            return response()->json([
                'message' => $createdUser ? 'Sub Editor linked successfully. Password setup email sent.' : $result['message'],
                'sub_editor' => [
                    'id' => $result['sub_editor']->id,
                    'name' => $result['sub_editor']->name,
                    'email' => $result['sub_editor']->email,
                ]
            ], $result['status']);

        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], $e->getCode() === 422 ? 422 : 500);
        }
    }

    /**
     * Remove the link between the Sub Editor and the authenticated Editor.
     */
    public function unassign(Request $request, int $subEditorId): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasRole('editor')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $subEditor = User::findOrFail($subEditorId);
        $editorsCount = $subEditor->assignedEditors()->count();

        $isLinked = $user->assignedSubEditors()->where('sub_editor_id', $subEditorId)->exists();
        if (!$isLinked) {
            return response()->json(['message' => 'Not assigned to this Sub Editor.'], 400);
        }

        if ($editorsCount <= 1) {
            return response()->json([
                'message' => 'This Sub Editor must remain assigned to at least one Editor.'
            ], 422);
        }

        $user->assignedSubEditors()->detach($subEditorId);

        return response()->json(['message' => 'Sub Editor unassigned successfully.']);
    }
}
