<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'nameFr' => $this->name_fr,
            'nameAr' => $this->name_ar,
            'descriptionShortFr' => $this->description_short_fr,
            'descriptionShortAr' => $this->description_short_ar,
            'descriptionFr' => $this->description_fr,
            'descriptionAr' => $this->description_ar,

            'categoryId' => $this->category_id ? (string) $this->category_id : null,
            'brandId' => $this->brand_id ? (string) $this->brand_id : null,

            'price' => (int) $this->price,
            'oldPrice' => $this->old_price === null ? null : (int) $this->old_price,
            'stock' => (int) $this->stock,
            'lowStockThreshold' => (int) $this->low_stock_threshold,
            'trackStock' => (bool) $this->track_stock,
            'allowBackorder' => (bool) $this->allow_backorder,
            'isActive' => (bool) $this->is_active,
            'isFeatured' => (bool) $this->is_featured,
            'isNew' => (bool) $this->is_new,
            'isBestSeller' => (bool) $this->is_best_seller,
            'isPromo' => (bool) $this->is_promo,

            'rating' => (float) $this->rating,
            'reviewCount' => (int) $this->review_count,
            'viewCount' => (int) $this->view_count,
            'soldCount' => (int) $this->sold_count,
            'stockStatus' => $this->stockStatus(),

            // Eloquent's `$model->attributes` shadows the JSON column accessor
            // since "attributes" is reserved. The column is exposed through
            // `getAttribute('attributes')` via cast, so use that.
            'attributes' => $this->getAttributeValue('attributes'),

            'variants' => $this->whenLoaded('variants', function () {
                return $this->variants->map(fn ($v) => [
                    'id' => (string) $v->id,
                    'colorNameFr' => $v->color_name_fr,
                    'colorNameAr' => $v->color_name_ar,
                    'colorHex' => $v->color_hex,
                    'sizeLabel' => $v->size_label,
                    'skuSuffix' => $v->sku_suffix,
                    'priceDelta' => (int) $v->price_delta,
                    'stock' => (int) $v->stock,
                    'displayOrder' => (int) $v->display_order,
                ])->values();
            }, []),

            'images' => $this->whenLoaded('images', function () {
                $base = rtrim((string) config('app.url'), '/');
                return $this->images->map(function ($img) use ($base) {
                    $url = $img->url;
                    if (is_string($url) && str_starts_with($url, '/storage/')) {
                        $url = $base.$url;
                    }
                    return [
                        'id' => (string) $img->id,
                        'url' => $url,
                        'altFr' => $img->alt_fr,
                        'altAr' => $img->alt_ar,
                        'displayOrder' => (int) $img->display_order,
                        'isPrimary' => (bool) $img->is_primary,
                    ];
                })->values();
            }, []),

            'category' => $this->whenLoaded('category', fn () => new CategoryResource($this->category)),
            'brand' => $this->whenLoaded('brand', fn () => new BrandResource($this->brand)),
        ];
    }
}
