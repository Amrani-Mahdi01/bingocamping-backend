<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Direct admin-account management — powers /admin/admins.
 *
 * Distinct from the "promote a customer" flow in CustomerController
 * (which reuses an existing customer hash). Here the acting admin
 * types a fresh password and the new admin row is created from
 * scratch. Useful for hiring a teammate who never shopped on the
 * storefront.
 */
class AdminController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = Admin::query()
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'email', 'role', 'is_active', 'last_seen_at', 'created_at']);

        return response()->json([
            'data' => $rows->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'email' => $a->email,
                'role' => $a->role,
                'isActive' => (bool) $a->is_active,
                'lastSeenAt' => $a->last_seen_at?->toIso8601String(),
                'createdAt' => $a->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'email', 'max:160',
                Rule::unique('admins', 'email'),
            ],
            'password' => ['required', 'string', 'min:8', 'max:120'],
            // Owners can only be seeded via the database — the UI no
            // longer exposes that role to keep the org chart simple.
            'role' => ['nullable', 'string', 'in:admin,staff'],
        ]);

        $admin = Admin::create([
            'name' => $data['name'],
            'email' => strtolower(trim($data['email'])),
            'password' => $data['password'], // hashed via model cast
            'role' => $data['role'] ?? 'admin',
            'is_active' => true,
        ]);

        return response()->json([
            'data' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                'isActive' => (bool) $admin->is_active,
                'lastSeenAt' => $admin->last_seen_at?->toIso8601String(),
                'createdAt' => $admin->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * DELETE /api/admin/admins/{admin}
     *
     * Owner-only. Refuses self-deletion (acting admin can't lock
     * themselves out mid-session) and refuses to delete the LAST
     * owner (so the system always retains one root account). All
     * Sanctum tokens for the deleted admin are revoked alongside
     * the row so any sessions they have open in other tabs / devices
     * get a 401 on their next request and bounce to /admin/login.
     */
    public function destroy(Request $request, Admin $admin): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof Admin || $actor->role !== 'owner') {
            return response()->json([
                'message' => "Action réservée au propriétaire.",
            ], 403);
        }

        if ($actor->id === $admin->id) {
            return response()->json([
                'message' => "Vous ne pouvez pas supprimer votre propre compte.",
            ], 422);
        }

        if ($admin->role === 'owner') {
            $otherOwners = Admin::where('role', 'owner')
                ->where('id', '!=', $admin->id)
                ->count();
            if ($otherOwners === 0) {
                return response()->json([
                    'message' => "Impossible de supprimer le dernier propriétaire.",
                ], 422);
            }
        }

        // Revoke every personal-access token issued to this admin so
        // open sessions can't keep acting after the row is gone.
        $admin->tokens()->delete();
        $admin->delete();

        return response()->json(['message' => 'Admin supprimé.']);
    }
}
