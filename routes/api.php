<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CommuneController;
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
    Route::post('/uploads/logo', [UploadController::class, 'logoImage']);
});
