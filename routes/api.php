<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\BackupController;
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
use App\Http\Controllers\Api\ZrExpressController;
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
// Guest checkout — throttled per IP to blunt order spam (reCAPTCHA also gates).
Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:20,1');
// Storefront /contact form — reCAPTCHA-gated (see controller) + throttled.
Route::post('/contact', [ContactMessageController::class, 'store'])->middleware('throttle:8,1');

// NOTE: the former public /_dev/sync-storage and /_dev/data-load routes were
// removed — they were Railway-era one-shot hacks gated only by a hardcoded
// secret (which ended up committed), and data-load reseeds the DB. Run the
// equivalent artisan commands on the server over SSH instead.

// SSE pending-count feed. Sits OUTSIDE the auth:sanctum guard because
// EventSource on the browser can't send custom headers; the admin
// token is passed as a query param and validated inside the controller.
Route::get('/admin/orders/stream', [OrderController::class, 'stream']);

/*
|--------------------------------------------------------------------------
| Customer API — storefront auth (Sanctum bearer tokens)
|--------------------------------------------------------------------------
*/

// Throttle account creation + login per IP to blunt enumeration / brute force.
Route::post('/auth/register', [CustomerAuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/auth/login',    [CustomerAuthController::class, 'login'])->middleware('throttle:10,1');

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

// Coarse per-IP backstop; AdminAuthController adds a finer per-email+IP lockout.
Route::post('/admin/login', [AdminAuthController::class, 'login'])->middleware('throttle:6,1');

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
    // Archive (reversible) instead of deleting — stale orders auto-archive.
    Route::post('/orders/{order}/archive', [OrderController::class, 'archive']);
    Route::post('/orders/{order}/unarchive', [OrderController::class, 'unarchive']);
    // ZR Express per-order actions: push/retry (ZR-01) + bordereau (ZR-05)
    Route::post('/orders/{order}/ship', [ZrExpressController::class, 'ship']);
    Route::get('/orders/{order}/label', [ZrExpressController::class, 'label']);
    // Clear ZR linkage when a parcel was deleted on ZR's side (unstick + resend).
    Route::post('/orders/{order}/zr-detach', [ZrExpressController::class, 'detach']);
    // Refresh THIS order's ZR status on demand (per-order button).
    Route::post('/orders/{order}/zr-sync', [ZrExpressController::class, 'syncOrder']);

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

    // Full-database backup & restore (Paramètres page).
    // Direct download (never stored on the server):
    Route::get('/backup/database', [BackupController::class, 'database']);
    // Server-side snapshots kept under storage/app/backups:
    Route::get('/backups', [BackupController::class, 'index']);
    Route::post('/backups', [BackupController::class, 'store']);
    Route::get('/backups/{name}', [BackupController::class, 'download'])
        ->where('name', '[A-Za-z0-9._-]+');
    Route::post('/backups/{name}/restore', [BackupController::class, 'restore'])
        ->where('name', '[A-Za-z0-9._-]+');
    Route::delete('/backups/{name}', [BackupController::class, 'destroy'])
        ->where('name', '[A-Za-z0-9._-]+');

    // ZR Express delivery integration
    Route::get('/zr/settings', [ZrExpressController::class, 'settings']);
    Route::put('/zr/settings', [ZrExpressController::class, 'updateSettings']);
    Route::post('/zr/test', [ZrExpressController::class, 'test']);
    Route::post('/zr/sync-territories', [ZrExpressController::class, 'syncTerritories']);
    Route::post('/zr/sync-rates', [ZrExpressController::class, 'syncRates']);
    Route::post('/zr/sync-statuses', [ZrExpressController::class, 'syncStatuses']);

    // Wilayas + communes are imported from ZR Express ("Synchroniser les
    // territoires") and are read-only here — admins can edit prices/delivery
    // days but NOT add/remove territories (ZR is the single source of truth).
    Route::get('/wilayas', [WilayaController::class, 'indexAdmin']);
    Route::put('/wilayas/{wilaya}', [WilayaController::class, 'update']);
    Route::get('/wilayas/{wilaya}/communes', [CommuneController::class, 'indexAdmin']);

    // Uploads
    Route::post('/uploads/banner-image', [UploadController::class, 'bannerImage']);
    Route::post('/uploads/category-image', [UploadController::class, 'categoryImage']);
    Route::post('/uploads/product-image', [UploadController::class, 'productImage']);
    Route::post('/uploads/product-video', [UploadController::class, 'productVideo']);
    Route::post('/uploads/logo', [UploadController::class, 'logoImage']);
});
