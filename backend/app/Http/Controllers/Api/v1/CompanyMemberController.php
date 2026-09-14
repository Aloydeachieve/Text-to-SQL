<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Dashboard;
use App\Models\SavedQuery;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CompanyMemberController extends Controller
{
    /**
     * List all members of the authenticated user's company.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $members = User::where('company_id', $companyId)
            ->select('id', 'name', 'email', 'role', 'company_id', 'created_at')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $members,
        ]);
    }

    /**
     * Add a new member to the company (Admin only).
     */
    public function store(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        if (!$currentUser->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'role' => 'required|string|in:admin,analyst,viewer',
            'password' => ['nullable', 'string', Password::min(8)],
        ]);

        $companyId = $currentUser->company_id;
        $password = !empty($validated['password']) ? $validated['password'] : Str::random(16);

        $member = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $password,
            'company_id' => $companyId,
            'role' => $validated['role'],
        ]);

        AuditLog::record(
            $companyId,
            $currentUser->id,
            'member_created',
            'user',
            $member->id,
            [
                'email' => $member->email,
                'role' => $member->role,
                'name' => $member->name,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Team member added successfully.',
            'data' => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->role,
                'company_id' => $member->company_id,
                'created_at' => $member->created_at,
            ],
        ], 201);
    }

    /**
     * Update a company member's role or details (Admin only).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();

        if (!$currentUser->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $targetUser = User::where('company_id', $currentUser->company_id)->find($id);

        if (!$targetUser) {
            return response()->json([
                'success' => false,
                'message' => 'Team member not found or unauthorized.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'role' => 'sometimes|required|string|in:admin,analyst,viewer',
        ]);

        // Safety invariant: A user cannot change their own role
        if (isset($validated['role']) && $validated['role'] !== $targetUser->role) {
            if ($currentUser->id === $targetUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot modify your own role.',
                ], 422);
            }

            // Safety invariant: Cannot demote the last admin in the company
            if ($targetUser->role === 'admin' && $validated['role'] !== 'admin') {
                $adminCount = User::where('company_id', $currentUser->company_id)
                    ->where('role', 'admin')
                    ->count();

                if ($adminCount <= 1) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot demote the only remaining admin in the company.',
                    ], 422);
                }
            }

            AuditLog::record(
                $currentUser->company_id,
                $currentUser->id,
                'member_role_changed',
                'user',
                $targetUser->id,
                [
                    'old_role' => $targetUser->role,
                    'new_role' => $validated['role'],
                    'target_email' => $targetUser->email,
                ]
            );
        }

        $targetUser->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Team member updated successfully.',
            'data' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
                'role' => $targetUser->role,
                'company_id' => $targetUser->company_id,
                'created_at' => $targetUser->created_at,
            ],
        ]);
    }

    /**
     * Remove a member from the company (Admin only).
     * Reassigns user resources to preserve company analytics.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $currentUser = $request->user();

        if (!$currentUser->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $targetUser = User::where('company_id', $currentUser->company_id)->find($id);

        if (!$targetUser) {
            return response()->json([
                'success' => false,
                'message' => 'Team member not found or unauthorized.',
            ], 404);
        }

        // Safety invariant: Cannot remove the last remaining admin
        if ($targetUser->role === 'admin') {
            $adminCount = User::where('company_id', $currentUser->company_id)
                ->where('role', 'admin')
                ->count();

            if ($adminCount <= 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot remove the only remaining admin in the company.',
                ], 422);
            }
        }

        // Safety invariant: Preserve company queries and dashboards by nullifying user_id
        SavedQuery::where('user_id', $targetUser->id)->update(['user_id' => null]);
        Dashboard::where('user_id', $targetUser->id)->update(['user_id' => null]);

        AuditLog::record(
            $currentUser->company_id,
            $currentUser->id,
            'member_removed',
            'user',
            $targetUser->id,
            [
                'email' => $targetUser->email,
                'role' => $targetUser->role,
                'name' => $targetUser->name,
            ]
        );

        $targetUser->delete();

        return response()->json([
            'success' => true,
            'message' => 'Team member removed successfully.',
        ]);
    }
}
