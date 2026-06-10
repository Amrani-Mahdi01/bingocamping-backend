<?php

/*
 * CORS — only the storefront origins listed in FRONTEND_URLS may call the
 * API. In production this becomes the Vercel domain(s); in dev it's the
 * Next.js localhost/127.0.0.1 pair. Comma-separated, no trailing slash.
 */

$origins = array_filter(
    array_map('trim', explode(',', env('FRONTEND_URLS', 'http://localhost:3000')))
);

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [
        // Allow every Vercel preview deploy (the `*-<hash>.vercel.app`
        // domains Vercel auto-generates per branch / PR). The production
        // domain should still be listed explicitly via FRONTEND_URLS so
        // it's discoverable from config rather than relying on the regex.
        '#^https://[a-z0-9-]+\.vercel\.app$#',
    ],

    'allowed_headers' => ['*'],

    // Let the storefront JS read the download filename off backup responses.
    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => true,

];
