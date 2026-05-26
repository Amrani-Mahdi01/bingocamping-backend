<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Logged-in customer cart. Two endpoints cover the whole flow:
 *
 *   GET  /api/auth/cart    → current items, each row joined with the
 *                            product + variant snapshot the storefront
 *                            needs (slug, name, image, brand, price,
 *                            color/size labels) so the client doesn't
 *                            have to look anything up afterwards.
 *
 *   PUT  /api/auth/cart    → bulk replace. Body: { items: [{ productSlug,
 *                            variantId?, qty }, …] }. Anything not in
 *                            the body is removed; rows are upserted by
 *                            (customer_id, product_id, variant_id).
 *
 * This shape lets the React cart context stay in charge of merging
 * (e.g. anonymous → logged in) and just push the resulting state in a
 * single round-trip per change.
 */
class CartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customer = $request->user();

        $rows = CartItem::query()
            ->with(['product.brand', 'product.images', 'variant'])
            ->where('customer_id', $customer->id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($row) => $this->serialize($row))->values(),
        ]);
    }

    public function replace(Request $request): JsonResponse
    {
        $customer = $request->user();

        $payload = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.productSlug' => ['required', 'string'],
            'items.*.variantId' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $incoming = $payload['items'] ?? [];

        // Resolve slugs → IDs once so the loop is cheap.
        $slugs = collect($incoming)->pluck('productSlug')->unique()->all();
        $products = Product::whereIn('slug', $slugs)->where('is_active', true)->get()->keyBy('slug');

        DB::transaction(function () use ($customer, $incoming, $products) {
            $keepKeys = [];

            foreach ($incoming as $item) {
                $product = $products->get($item['productSlug']);
                if (! $product) {
                    // Silently skip slugs that don't resolve (e.g. product
                    // was disabled while in the customer's cart).
                    continue;
                }
                $variantId = $item['variantId'] ?? null;
                if ($variantId !== null) {
                    // Reject variants that don't belong to this product.
                    $belongs = ProductVariant::where('id', $variantId)
                        ->where('product_id', $product->id)
                        ->exists();
                    if (! $belongs) {
                        $variantId = null;
                    }
                }

                $row = CartItem::updateOrCreate(
                    [
                        'customer_id' => $customer->id,
                        'product_id' => $product->id,
                        'variant_id' => $variantId,
                    ],
                    ['qty' => (int) $item['qty']],
                );
                $keepKeys[] = $row->id;
            }

            // Drop anything the client didn't include in this push.
            CartItem::query()
                ->where('customer_id', $customer->id)
                ->whereNotIn('id', $keepKeys ?: [0])
                ->delete();
        });

        return $this->index($request);
    }

    public function clear(Request $request): JsonResponse
    {
        $customer = $request->user();
        CartItem::where('customer_id', $customer->id)->delete();
        return response()->json(['data' => []]);
    }

    /** Project a CartItem row to the shape the storefront expects. */
    private function serialize(CartItem $row): array
    {
        $p = $row->product;
        $v = $row->variant;
        $primary = $p?->images?->firstWhere('is_primary', true) ?? $p?->images?->first();

        // Match ProductResource: storage paths are stored relative
        // (`/storage/products/xxx.jpg`) so the storefront — which lives
        // on a different origin — must receive them absolute.
        $imageUrl = $primary?->url;
        if (is_string($imageUrl) && str_starts_with($imageUrl, '/storage/')) {
            $imageUrl = rtrim((string) config('app.url'), '/').$imageUrl;
        }

        return [
            'id' => $row->id,
            'qty' => $row->qty,
            'product' => [
                'slug' => $p?->slug,
                'nameFr' => $p?->name_fr,
                'nameAr' => $p?->name_ar,
                'brand' => $p?->brand?->name,
                'price' => $p?->price,
                'oldPrice' => $p?->old_price,
                'image' => $imageUrl,
                'categorySlug' => $p?->category?->slug,
            ],
            'variant' => $v ? [
                'id' => $v->id,
                'colorNameFr' => $v->color_name_fr,
                'colorNameAr' => $v->color_name_ar,
                'colorHex' => $v->color_hex,
                'sizeLabel' => $v->size_label,
                'priceDelta' => $v->price_delta,
            ] : null,
        ];
    }
}
