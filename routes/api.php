<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\BlockedIpController;
use App\Http\Controllers\Api\BlockedPhoneController;
use App\Http\Controllers\Api\CustomerAuthController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CommuneController;
use App\Http\Controllers\Api\ContactMessageController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\WilayaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API
|--------------------------------------------------------------------------
*/

Route::get('/banners', [BannerController::class, 'indexPublic']);
Route::get('/brands', [BrandController::class, 'indexPublic']);
Route::get('/categories', [CategoryController::class, 'indexPublic']);
Route::get('/products', [ProductController::class, 'indexPublic']);
Route::get('/products/{slug}', [ProductController::class, 'showPublic']);
Route::get('/settings', [SettingsController::class, 'indexPublic']);
Route::get('/wilayas', [WilayaController::class, 'indexPublic']);
Route::get('/wilayas/{wilaya}/communes', [CommuneController::class, 'indexPublic']);
Route::post('/orders', [OrderController::class, 'store']);
// Storefront /contact form — reCAPTCHA-gated (see controller).
Route::post('/contact', [ContactMessageController::class, 'store']);

/*
 * TEMP: one-shot storage sync. The local-dev image tree lives in the
 * repo under database/import-data/storage/, but the Railway Volume
 * mounts AFTER the boot command runs, so a `cp` in nixpacks.toml's
 * start cmd ends up shadowed. This route does the copy inside an
 * HTTP request handler — by then the volume is in place — and is
 * gated by a one-time secret so it can't be hit at random. Delete
 * once the prod storage is populated.
 */
Route::post('/_dev/sync-storage/{secret}', function (string $secret) {
    if ($secret !== 'sync-7K3Lp9vQ8mZxN2RfBcDeFgHi') {
        return response()->json(['error' => 'forbidden'], 403);
    }
    $bundle = base_path('database/import-data/storage');
    if (! is_dir($bundle)) {
        return response()->json(['error' => 'no bundle at '.$bundle], 404);
    }
    $dest = storage_path('app/public');
    \Illuminate\Support\Facades\File::ensureDirectoryExists($dest);
    \Illuminate\Support\Facades\File::copyDirectory($bundle, $dest);

    $count = 0;
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dest)) as $f) {
        if ($f->isFile()) $count++;
    }
    return response()->json([
        'ok' => true,
        'bundle' => $bundle,
        'dest' => $dest,
        'files_in_dest_after_copy' => $count,
    ]);
});
Route::post('/_dev/data-load/{secret}', function (string $secret) {
    if ($secret !== 'sync-7K3Lp9vQ8mZxN2RfBcDeFgHi') {
        return response()->json(['error' => 'forbidden'], 403);
    }
    \Illuminate\Support\Facades\Artisan::call('data:load');
    return response()->json([
        'ok' => true,
        'output' => \Illuminate\Support\Facades\Artisan::output(),
    ]);
});
// SSE pending-count feed. Sits OUTSIDE the auth:sanctum guard because
// EventSource on the browser can't send custom headers; the admin
// token is passed as a query param and validated inside the controller.
Route::get('/admin/orders/stream', [OrderController::class, 'stream']);

/*
|--------------------------------------------------------------------------
| Customer API — storefront auth (Sanctum bearer tokens)
|--------------------------------------------------------------------------
*/

Route::post('/auth/register', [CustomerAuthController::class, 'register']);
Route::post('/auth/login',    [CustomerAuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [CustomerAuthController::class, 'logout']);
    Route::get('/auth/me',      [CustomerAuthController::class, 'me']);

    // Storefront "Mes commandes" — list of orders the authenticated
    // customer has placed (scoped to customer_id, so guests checking
    // out with the same phone number aren't included).
    Route::get('/auth/orders',  [CustomerAuthController::class, 'orders']);

    // Logged-in cart — anonymous visitors use localStorage on the client.
    Route::get('/auth/cart',    [CartController::class, 'index']);
    Route::put('/auth/cart',    [CartController::class, 'replace']);
    Route::delete('/auth/cart', [CartController::class, 'clear']);

    // Logged-in favorites — same dual-mode pattern as the cart.
    Route::get('/auth/favorites',    [FavoriteController::class, 'index']);
    Route::put('/auth/favorites',    [FavoriteController::class, 'replace']);
    Route::delete('/auth/favorites', [FavoriteController::class, 'clear']);
});

/*
|--------------------------------------------------------------------------
| Admin API — auth + protected resources
|--------------------------------------------------------------------------
*/

Route::post('/admin/login', [AdminAuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::post('/logout', [AdminAuthController::class, 'logout']);
    Route::get('/me', [AdminAuthController::class, 'me']);

    // Banners
    Route::get('/banners', [BannerController::class, 'indexAdmin']);
    Route::post('/banners', [BannerController::class, 'store']);
    Route::put('/banners/{banner}', [BannerController::class, 'update']);
    Route::delete('/banners/{banner}', [BannerController::class, 'destroy']);
    Route::post('/banners/{banner}/move', [BannerController::class, 'move']);

    // Brands
    Route::get('/brands', [BrandController::class, 'indexAdmin']);
    Route::post('/brands', [BrandController::class, 'store']);
    Route::put('/brands/{brand}', [BrandController::class, 'update']);
    Route::delete('/brands/{brand}', [BrandController::class, 'destroy']);

    // Categories
    Route::get('/categories', [CategoryController::class, 'indexAdmin']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::post('/categories/reorder', [CategoryController::class, 'reorder']);
    Route::put('/categories/{category}', [CategoryController::class, 'update']);
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
    Route::post('/categories/{category}/move', [CategoryController::class, 'move']);

    // Products
    Route::get('/products', [ProductController::class, 'indexAdmin']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::put('/products/{product}', [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);

    // Orders — `pending-count` must be declared BEFORE the catch-all `{order}`
    // routes so it doesn't get swallowed as an ID lookup.
    Route::get('/orders/pending-count', [OrderController::class, 'pendingCount']);
    Route::get('/orders', [OrderController::class, 'indexAdmin']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);
    Route::post('/orders/{order}/calls', [OrderController::class, 'logCall']);
    Route::delete('/orders/{order}', [OrderController::class, 'destroy']);

    // Customers (aggregated from orders by phone)
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::get('/customers/{phone}', [CustomerController::class, 'show']);
    // Customer-row admin actions
    Route::post('/customers/{phone}/promote', [CustomerController::class, 'promote']);
    Route::post('/customers/{phone}/block-ip', [CustomerController::class, 'blockIp']);
    // Same as above, addressed by customer id — used when the
    // customer has no phone (registered via email only).
    Route::post('/customers/by-id/{customer}/promote', [CustomerController::class, 'promoteById']);

    // Admin accounts — direct create (vs. promote-a-customer in CustomerController)
    Route::get('/admins', [AdminController::class, 'index']);
    Route::post('/admins', [AdminController::class, 'store']);
    Route::delete('/admins/{admin}', [AdminController::class, 'destroy']);

    // IP blocklist
    Route::get('/blocked-ips', [BlockedIpController::class, 'index']);
    Route::post('/blocked-ips', [BlockedIpController::class, 'store']);
    Route::delete('/blocked-ips/{blockedIp}', [BlockedIpController::class, 'destroy']);

    // Phone-number blocklist — VPN bypasses IP blocks, phones stick.
    Route::get('/blocked-phones', [BlockedPhoneController::class, 'index']);
    Route::post('/blocked-phones', [BlockedPhoneController::class, 'store']);
    Route::delete('/blocked-phones/{blockedPhone}', [BlockedPhoneController::class, 'destroy']);

    // Contact-form inbox — read by the admin Messages page.
    Route::get('/contact-messages', [ContactMessageController::class, 'index']);
    Route::patch('/contact-messages/{message}', [ContactMessageController::class, 'update']);
    Route::delete('/contact-messages/{message}', [ContactMessageController::class, 'destroy']);

    // Stats
    Route::get('/stats/dashboard', [StatsController::class, 'dashboard']);

    // Settings
    Route::get('/settings', [SettingsController::class, 'indexAdmin']);
    Route::put('/settings', [SettingsController::class, 'update']);

    // Wilayas — shipping price + delivery days per wilaya
    Route::get('/wilayas', [WilayaController::class, 'indexAdmin']);
    Route::post('/wilayas', [WilayaController::class, 'store']);
    Route::put('/wilayas/{wilaya}', [WilayaController::class, 'update']);

    // Communes nested under their wilaya
    Route::get('/wilayas/{wilaya}/communes', [CommuneController::class, 'indexAdmin']);
    Route::post('/wilayas/{wilaya}/communes', [CommuneController::class, 'store']);
    Route::delete('/wilayas/{wilaya}/communes/{commune}', [CommuneController::class, 'destroy']);

    // Uploads
    Route::post('/uploads/banner-image', [UploadController::class, 'bannerImage']);
    Route::post('/uploads/category-image', [UploadController::class, 'categoryImage']);
    Route::post('/uploads/product-image', [UploadController::class, 'productImage']);
    Route::post('/uploads/product-video', [UploadController::class, 'productVideo']);
    Route::post('/uploads/logo', [UploadController::class, 'logoImage']);
});
