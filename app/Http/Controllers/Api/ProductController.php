<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /**
     * GET /api/products — public storefront listing.
     *
     * Query params:
     *  - q                search across FR/AR/SKU/brand
     *  - category         category slug (matches the leaf or any descendant)
     *  - brand            brand slug
     *  - promoOnly        truthy → only promo products
     *  - flag             "featured" | "new" | "bestseller" | "promo"
     *  - sort             "new" | "price-asc" | "price-desc" | "bestseller"
     *  - page, perPage    pagination
     */
    public function indexPublic(Request $request): JsonResponse
    {
        $q = Product::query()
            ->with(['category', 'brand', 'images', 'variants'])
            ->where('is_active', true);

        if ($search = trim((string) $request->query('q', ''))) {
            $like = '%'.$search.'%';
            $q->where(function ($qq) use ($like) {
                $qq->where('name_fr', 'like', $like)
                   ->orWhere('name_ar', 'like', $like)
                   ->orWhere('sku', 'like', $like)
                   ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', $like));
            });
        }
        if ($catSlug = $request->query('category')) {
            $q->whereHas('category', function ($cq) use ($catSlug) {
                $cq->where('slug', $catSlug)->orWhereHas('parent', fn ($p) => $p->where('slug', $catSlug));
            });
        }
        if ($brandSlug = $request->query('brand')) {
            $q->whereHas('brand', fn ($b) => $b->where('slug', $brandSlug));
        }
        if (filter_var($request->query('promoOnly', false), FILTER_VALIDATE_BOOLEAN)) {
            $q->where('is_promo', true);
        }
        // Price range (DZD integers). Both ends inclusive; either can be omitted.
        if (($min = $request->query('minPrice')) !== null && $min !== '') {
            $q->where('price', '>=', (int) $min);
        }
        if (($max = $request->query('maxPrice')) !== null && $max !== '') {
            $q->where('price', '<=', (int) $max);
        }
        // In-stock filter: exclude tracked products at 0 stock that don't
        // allow backorder.
        if (filter_var($request->query('inStockOnly', false), FILTER_VALIDATE_BOOLEAN)) {
            $q->where(function ($qq) {
                $qq->where('track_stock', false)
                   ->orWhere('allow_backorder', true)
                   ->orWhere('stock', '>', 0);
            });
        }
        if ($flag = $request->query('flag')) {
            $map = [
                'featured' => 'is_featured',
                'new' => 'is_new',
                'bestseller' => 'is_best_seller',
                'promo' => 'is_promo',
            ];
            if (isset($map[$flag])) {
                $q->where($map[$flag], true);
            }
        }

        $sort = $request->query('sort', 'new');
        match ($sort) {
            'price-asc'   => $q->orderBy('price'),
            'price-desc'  => $q->orderByDesc('price'),
            'bestseller'  => $q->orderByDesc('sold_count')->orderByDesc('created_at'),
            default       => $q->orderByDesc('created_at'),
        };

        $perPage = min((int) $request->query('perPage', 24), 100);
        $paginator = $q->paginate($perPage);

        return response()->json([
            'data' => ProductResource::collection($paginator->items()),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    /** GET /api/products/{slug} — public product detail by slug. */
    public function showPublic(Request $request, string $slug): JsonResponse
    {
        $product = Product::query()
            ->with(['category', 'brand', 'images', 'variants'])
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        // Track a view (best-effort; never fails the request).
        try {
            $product->increment('view_count');
        } catch (\Throwable) {
            /* ignore */
        }

        return response()->json([
            'data' => (new ProductResource($product))->toArray($request),
        ]);
    }

    /** GET /api/admin/products — paginated listing for the dashboard. */
    public function indexAdmin(Request $request): JsonResponse
    {
        $q = Product::query()
            ->with(['category', 'brand', 'images', 'variants'])
            ->orderByDesc('created_at');

        if ($search = trim((string) $request->query('q', ''))) {
            $like = '%'.$search.'%';
            $q->where(function ($qq) use ($like) {
                $qq->where('name_fr', 'like', $like)
                   ->orWhere('name_ar', 'like', $like)
                   ->orWhere('sku', 'like', $like)
                   ->orWhere('slug', 'like', $like);
            });
        }
        if ($categoryId = $request->query('categoryId')) {
            $q->where('category_id', $categoryId);
        }
        if ($brandId = $request->query('brandId')) {
            $q->where('brand_id', $brandId);
        }

        $perPage = min((int) $request->query('perPage', 25), 100);
        $paginator = $q->paginate($perPage);

        return response()->json([
            'data' => ProductResource::collection($paginator->items()),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    /** GET /api/admin/products/{product} */
    public function show(Product $product): ProductResource
    {
        return new ProductResource(
            $product->load(['category', 'brand', 'images', 'variants'])
        );
    }

    /** POST /api/admin/products */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = DB::transaction(function () use ($request) {
            $data = $request->toModel();
            // Store the video as a host-relative /storage/ path (like images)
            // so it survives a move between hosts (Railway → Hostinger).
            $data['video'] = $this->normalizeStoragePath($data['video'] ?? null);
            $p = Product::create($data);
            $this->syncImages($p, $request);
            $this->syncVariants($p, $request);
            $this->resyncStockFromVariants($p);
            return $p;
        });

        return (new ProductResource(
            $product->load(['category', 'brand', 'images', 'variants'])
        ))->response()->setStatusCode(201);
    }

    /** PUT /api/admin/products/{product} */
    public function update(StoreProductRequest $request, Product $product): ProductResource
    {
        DB::transaction(function () use ($request, $product) {
            $oldVideo = $product->video;
            $data = $request->toModel();
            $data['video'] = $this->normalizeStoragePath($data['video'] ?? null);
            $product->update($data);
            // Old video replaced or removed → delete the orphaned file.
            if ($oldVideo && $oldVideo !== $product->video) {
                $this->deleteStoredFile($oldVideo);
            }
            if ($request->has('images')) {
                $this->syncImages($product, $request, replace: true);
            }
            if ($request->has('variants')) {
                $this->syncVariants($product, $request, replace: true);
                $this->resyncStockFromVariants($product);
            }
        });

        return new ProductResource(
            $product->fresh(['category', 'brand', 'images', 'variants'])
        );
    }

    private function syncImages(Product $product, Request $request, bool $replace = false): void
    {
        if ($replace) {
            // Delete files for images no longer present in the new set so they
            // don't pile up as orphans. Kept images (same URL) are untouched.
            $newUrls = [];
            foreach ((array) $request->input('images', []) as $img) {
                $u = $this->normalizeStoragePath($img['url'] ?? null);
                if ($u) $newUrls[] = $u;
            }
            foreach ($product->images as $old) {
                if (! in_array($old->url, $newUrls, true)) {
                    $this->deleteStoredFile($old->url);
                }
            }
            $product->images()->delete();
        }
        foreach ((array) $request->input('images', []) as $i => $img) {
            $url = $this->normalizeStoragePath($img['url'] ?? null);
            if (! $url) continue;
            ProductImage::create([
                'product_id' => $product->id,
                'url' => $url,
                'alt_fr' => $img['altFr'] ?? null,
                'alt_ar' => $img['altAr'] ?? null,
                'display_order' => $i,
                'is_primary' => $i === 0,
            ]);
        }
    }

    private function syncVariants(Product $product, Request $request, bool $replace = false): void
    {
        if ($replace) {
            $product->variants()->delete();
        }
        foreach ((array) $request->input('variants', []) as $i => $v) {
            $colorFr = trim((string) ($v['colorNameFr'] ?? '')) ?: null;
            $colorAr = trim((string) ($v['colorNameAr'] ?? '')) ?: null;
            $colorHex = trim((string) ($v['colorHex'] ?? '')) ?: null;
            $sizeLabel = trim((string) ($v['sizeLabel'] ?? '')) ?: null;
            // Skip rows with no color (name or swatch) AND no size — not purchasable.
            if (! $colorFr && ! $colorAr && ! $colorHex && ! $sizeLabel) {
                continue;
            }
            ProductVariant::create([
                'product_id' => $product->id,
                'color_name_fr' => $colorFr,
                'color_name_ar' => $colorAr,
                'color_hex' => $colorHex,
                'size_label' => $sizeLabel,
                'sku_suffix' => $v['skuSuffix'] ?? null,
                'price_delta' => (int) ($v['priceDelta'] ?? 0),
                'stock' => (int) ($v['stock'] ?? 0),
                'display_order' => $i,
            ]);
        }
    }

    /**
     * When a product has variants, its total stock is the sum of the variant
     * stocks (the variants own the inventory). Keeps the product-level number
     * honest so it can never contradict the per-colour quantities. No-op for
     * simple products, which keep their manually-entered total.
     */
    private function resyncStockFromVariants(Product $product): void
    {
        if ($product->variants()->exists()) {
            $product->stock = (int) $product->variants()->sum('stock');
            $product->save();
        }
    }

    /** DELETE /api/admin/products/{product} */
    public function destroy(Product $product): JsonResponse
    {
        // Delete the product's media off disk before the rows cascade away,
        // otherwise the files become orphans nothing references.
        foreach ($product->images as $img) {
            $this->deleteStoredFile($img->url);
        }
        $this->deleteStoredFile($product->video);

        $product->delete();
        return response()->json(['message' => 'Product deleted.']);
    }

    /**
     * Accepts an absolute URL pointing at our own /storage/* and strips
     * the host so the DB stores a portable relative path. Leaves all
     * other URLs (or already-relative paths) untouched.
     */
    private function normalizeStoragePath(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') return null;
        $base = rtrim((string) config('app.url'), '/');
        if (str_starts_with($value, $base.'/storage/')) {
            return substr($value, strlen($base));
        }
        return $value;
    }

    /**
     * Delete a file we previously stored on the public disk, given either a
     * relative "/storage/..." path or an absolute URL pointing at it. No-op
     * for empty values or anything that isn't one of our /storage/ files.
     */
    private function deleteStoredFile(?string $value): void
    {
        if (! is_string($value) || trim($value) === '') return;
        // Drop scheme+host if it's an absolute URL, keeping just the path.
        $path = parse_url($value, PHP_URL_PATH) ?: $value;
        $marker = '/storage/';
        $pos = strpos($path, $marker);
        if ($pos === false) return;
        $relative = substr($path, $pos + strlen($marker)); // e.g. products/x.webp
        if ($relative === '') return;
        Storage::disk('public')->delete($relative);
    }
}
