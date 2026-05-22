<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    /**
     * POST /api/admin/login
     * Body: { email, password }
     * Returns: { token, admin: { id, name, email, role } }
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = Admin::where('email', strtolower(trim($data['email'])))->first();

        if (! $admin || ! Hash::check($data['password'], $admin->password)) {
            throw ValidationException::withMessages([
                'email' => ['Identifiants invalides.'],
            ]);
        }

        if (! $admin->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Compte désactivé.'],
            ]);
        }

        // Single fresh token per session — drop any older ones for this UA.
        $admin->tokens()->where('name', 'admin-session')->delete();
        $token = $admin->createToken('admin-session', ['admin']);

        $admin->forceFill(['last_seen_at' => now()])->save();

        return response()->json([
            'token' => $token->plainTextToken,
            'admin' => $this->serialize($admin),
        ]);
    }

    /**
     * POST /api/admin/logout
     * Revokes the bearer token used to make the request.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }
        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * GET /api/admin/me
     * Returns the authenticated admin's profile.
     */
    public function me(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        return response()->json(['admin' => $this->serialize($admin)]);
    }

    private function serialize(Admin $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role,
            'isActive' => (bool) $admin->is_active,
            'lastSeenAt' => $admin->last_seen_at?->toIso8601String(),
        ];
    }
}
