<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Railway (and most PaaS hosts) terminate TLS at an edge proxy and
        // forward requests internally over HTTP with X-Forwarded-* headers.
        // Without trusting those headers Laravel thinks every request is
        // http:// + a private IP — which breaks generated URLs (storage,
        // Sanctum cookies, password-reset links). '*' is safe here because
        // Railway only routes traffic that originated at its edge.
        $middleware->trustProxies(at: '*');

        // Treat the whole /api surface as JSON so framework exceptions render
        // as JSON (clean 401 instead of a redirect-to-login 500). Prepended so
        // it runs before auth:sanctum.
        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        $middleware->alias([
            'admin' => EnsureAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Treat every /api/* request as a JSON client so framework exceptions
        // (unauthenticated, validation, 404, …) render as JSON. Without this,
        // an unauthenticated hit with no Accept header makes the handler try to
        // redirect to the non-existent `login` route, surfacing as a 500
        // instead of a clean 401.
        $exceptions->shouldRenderJsonWhen(
            fn (\Illuminate\Http\Request $request, \Throwable $e) =>
                $request->is('api/*') || $request->expectsJson()
        );
    })->create();
