<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
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

        $email = strtolower(trim($data['email']));

        // Brute-force lockout, keyed per email + IP: after 10 failed attempts
        // the pair is locked for 60s. This sits under the coarse per-IP route
        // throttle and is the precise gate (an attacker rotating emails or a
        // shared IP can't burn another account's budget). 10 (not 5) so a
        // legitimate admin fumbling the password a few times isn't locked out.
        $throttleKey = 'admin-login:'.$email.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            throw ValidationException::withMessages([
                'email' => ["Trop de tentatives. Réessayez dans {$seconds} seconde(s)."],
            ])->status(429);
        }

        $admin = Admin::where('email', $email)->first();

        if (! $admin || ! Hash::check($data['password'], $admin->password)) {
            RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages([
                'email' => ['Identifiants invalides.'],
            ]);
        }

        if (! $admin->is_active) {
            RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages([
                'email' => ['Compte désactivé.'],
            ]);
        }

        // Successful login clears the failed-attempt counter for this pair.
        RateLimiter::clear($throttleKey);

        // Single fresh token per session — drop any older ones for this UA.
        // The token also carries a hard expiry so a leaked token can't live
        // forever; logging back in mints a new one.
        $admin->tokens()->where('name', 'admin-session')->delete();
        $token = $admin->createToken('admin-session', ['admin'], now()->addDays(7));

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
