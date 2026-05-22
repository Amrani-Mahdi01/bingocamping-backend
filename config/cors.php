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
        // Allow every Vercel preview deploy that ends in .vercel.app once
        // we have the project name. Comment out until we deploy.
        // '#^https://bingo-[a-z0-9-]+\.vercel\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
