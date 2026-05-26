<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Logged-in customer favorites. Mirrors the cart API: anonymous users
 * keep favorites in localStorage on the client; this controller takes
 * over once the user authenticates and lets favorites follow them
 * across devices.
 *
 *   GET  /api/auth/favorites   → list of joined product rows
 *   PUT  /api/auth/favorites   → bulk replace by slug array
 *   DELETE /api/auth/favorites → clear all
 */
class FavoriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customer = $request->user();

        $rows = DB::table('favorites')
            ->join('products', 'favorites.product_id', '=', 'products.id')
            ->leftJoin('brands', 'products.brand_id', '=', 'brands.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->where('favorites.customer_id', $customer->id)
            ->where('products.is_active', true)
            ->orderBy('favorites.created_at')
            ->get([
                'products.id as product_id',
                'products.slug',
                'products.name_fr',
                'products.name_ar',
                'products.price',
                'products.old_price',
                'brands.name as brand_name',
                'categories.slug as category_slug',
            ]);

        // Eager-load primary image for each product in one extra query
        // instead of N+1.
        $productIds = $rows->pluck('product_id')->all();
        $images = DB::table('product_images')
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->orderByDesc('is_primary')
            ->orderBy('display_order')
            ->get(['product_id', 'url', 'is_primary'])
            ->groupBy('product_id');

        // Match ProductResource: storage paths are stored relative
        // (`/storage/products/xxx.jpg`) so the storefront — which lives
        // on a different origin — must receive them absolute.
        $base = rtrim((string) config('app.url'), '/');

        return response()->json([
            'data' => $rows->map(function ($r) use ($images, $base) {
                $img = $images->get($r->product_id)?->first();
                $imageUrl = $img?->url;
                if (is_string($imageUrl) && str_starts_with($imageUrl, '/storage/')) {
                    $imageUrl = $base.$imageUrl;
                }
                return [
                    'slug' => $r->slug,
                    'nameFr' => $r->name_fr,
                    'nameAr' => $r->name_ar,
                    'brand' => $r->brand_name,
                    'price' => (int) $r->price,
                    'oldPrice' => $r->old_price !== null ? (int) $r->old_price : null,
                    'image' => $imageUrl,
                    'categorySlug' => $r->category_slug,
                ];
            })->values(),
        ]);
    }

    public function replace(Request $request): JsonResponse
    {
        $customer = $request->user();
        $payload = $request->validate([
            'slugs' => ['nullable', 'array'],
            'slugs.*' => ['string'],
        ]);
        $slugs = collect($payload['slugs'] ?? [])->unique()->values()->all();

        $productIds = Product::whereIn('slug', $slugs)
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($customer, $productIds) {
            DB::table('favorites')->where('customer_id', $customer->id)->delete();
            if (empty($productIds)) {
                return;
            }
            $now = now();
            $rows = array_map(
                fn ($pid) => [
                    'customer_id' => $customer->id,
                    'product_id' => $pid,
                    'created_at' => $now,
                ],
                $productIds,
            );
            DB::table('favorites')->insert($rows);
        });

        return $this->index($request);
    }

    public function clear(Request $request): JsonResponse
    {
        $customer = $request->user();
        DB::table('favorites')->where('customer_id', $customer->id)->delete();
        return response()->json(['data' => []]);
    }
}
