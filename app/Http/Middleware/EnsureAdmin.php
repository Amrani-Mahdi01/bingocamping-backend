<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    /**
     * Reject requests whose authenticated user isn't an Admin instance,
     * even if a valid Sanctum token is present. Tokens issued for Customer
     * models will fail here; only admin-issued tokens pass.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof Admin) {
            return response()->json(
                ['message' => 'Forbidden — admin access required.'],
                403,
            );
        }

        if (! $user->is_active) {
            return response()->json(
                ['message' => 'Account is disabled.'],
                403,
            );
        }

        return $next($request);
    }
}
