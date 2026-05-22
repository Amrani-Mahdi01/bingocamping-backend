<?php

use App\Models\Admin;
use App\Models\Customer;

return [

    /*
    | Two guards: `customer` (storefront) and `admin` (dashboard). They live
    | on separate tables/models so an admin account can never accidentally
    | authenticate as a customer or vice-versa.
    |
    | Sanctum personal-access tokens are used by both — Bearer header,
    | stateless, friendly to a cross-origin Vercel ↔ Hostinger setup.
    */

    'defaults' => [
        // Default guard is `customer` since that's the broader audience.
        // Admin routes use the `auth:admin` middleware explicitly.
        'guard' => env('AUTH_GUARD', 'customer'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'customers'),
    ],

    'guards' => [
        'customer' => [
            'driver' => 'session',
            'provider' => 'customers',
        ],
        'admin' => [
            'driver' => 'session',
            'provider' => 'admins',
        ],
    ],

    'providers' => [
        'customers' => [
            'driver' => 'eloquent',
            'model' => Customer::class,
        ],
        'admins' => [
            'driver' => 'eloquent',
            'model' => Admin::class,
        ],
    ],

    'passwords' => [
        'customers' => [
            'provider' => 'customers',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
        'admins' => [
            'provider' => 'admins',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
