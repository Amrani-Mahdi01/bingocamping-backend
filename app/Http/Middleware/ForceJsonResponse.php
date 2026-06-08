<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force every API request to be treated as a JSON client.
 *
 * The storefront/admin SPA always sends `Accept: application/json`, but raw
 * callers (curl, Postman, link previewers) may not. Without a JSON Accept
 * header Laravel's auth handler tries to redirect unauthenticated requests to
 * the non-existent `login` route, which surfaces as a 500 instead of a clean
 * 401. Stamping the header here guarantees framework exceptions (auth,
 * validation, 404, …) render as JSON for the whole /api surface.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
