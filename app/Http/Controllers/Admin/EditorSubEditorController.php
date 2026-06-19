<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class EditorSubEditorController extends Controller
{
    /**
     * Display a listing of Sub Editors assigned to the authenticated Editor.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasRole('super_admin') && !$user->hasRole('admin') && !$user->hasRole('editor') && !$user->hasRole('magazine_editor') && !$user->hasRole('magazine-editor')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($user->hasRole('super_admin') || $user->hasRole('admin')) {
            $subEditors = User::whereHas('role', function ($query) {
                $query->where('name', 'sub_editor')->orWhere('name', 'sub-editor');
            })->get();
        } else {
            $subEditors = $user->assignedSubEditors;
        }

        $data = $subEditors->map(function ($subEditor) {
            return [
                'id' => $subEditor->id,
                'name' => $subEditor->name,
                'email' => $subEditor->email,
                'created_at' => $subEditor->created_at,
                'assigned_at' => ($subEditor->pivot && isset($subEditor->pivot->created_at)) ? $subEditor->pivot->created_at : $subEditor->created_at,
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

        if (!$user->hasRole('super_admin') && !$user->hasRole('admin') && !$user->hasRole('editor') && !$user->hasRole('magazine_editor') && !$user->hasRole('magazine-editor')) {
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

        $subEditor = User::where('email', $validated['email'])->first();

        if (!$subEditor) {
            $subEditor = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make('Password123!'),
                'role_id' => $role->id,
                'email_verified_at' => now(),
                'needs_password_reset' => true,
                'university_name' => $user->university_name,
                'current_email_verified' => true,
            ]);
        } else {
            // Update to sub_editor role
            $subEditor->role_id = $role->id;
            $subEditor->save();
        }

        // Link with Editor if logged in user is editor
        if ($user->hasRole('editor') || $user->hasRole('magazine_editor') || $user->hasRole('magazine-editor')) {
            if (!$user->assignedSubEditors()->where('sub_editor_id', $subEditor->id)->exists()) {
                $user->assignedSubEditors()->attach($subEditor->id, ['created_by' => $user->id]);
            }
        }

        return response()->json([
            'message' => 'Sub Editor linked successfully.',
            'sub_editor' => [
                'id' => $subEditor->id,
                'name' => $subEditor->name,
                'email' => $subEditor->email,
            ]
        ], 201);
    }

    /**
     * Remove the link between the Sub Editor and the authenticated Editor.
     */
    public function unassign(Request $request, int $subEditorId): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasRole('super_admin') && !$user->hasRole('admin') && !$user->hasRole('editor') && !$user->hasRole('magazine_editor') && !$user->hasRole('magazine-editor')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($user->hasRole('editor') || $user->hasRole('magazine_editor') || $user->hasRole('magazine-editor')) {
            $user->assignedSubEditors()->detach($subEditorId);
        }

        return response()->json(['message' => 'Sub Editor unassigned successfully.']);
    }
}
