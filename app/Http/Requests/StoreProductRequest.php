<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('product')?->id;
        return [
            'slug' => [
                'nullable', 'string', 'max:140', 'alpha_dash',
                'unique:products,slug,'.($id ?? 'NULL').',id',
            ],
            'sku' => [
                'nullable', 'string', 'max:64',
                'unique:products,sku,'.($id ?? 'NULL').',id',
            ],
            'nameFr' => ['required', 'string', 'max:200'],
            'nameAr' => ['required', 'string', 'max:200'],
            'descriptionShortFr' => ['nullable', 'string', 'max:500'],
            'descriptionShortAr' => ['nullable', 'string', 'max:500'],
            'descriptionFr' => ['nullable', 'string', 'max:5000'],
            'descriptionAr' => ['nullable', 'string', 'max:5000'],

            'categoryId' => ['required', 'integer', 'exists:categories,id'],
            'brandId' => ['required', 'integer', 'exists:brands,id'],

            'price' => ['required', 'integer', 'min:0'],
            'oldPrice' => ['nullable', 'integer', 'min:0', 'gte:price'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'lowStockThreshold' => ['nullable', 'integer', 'min:0'],
            'trackStock' => ['nullable', 'boolean'],
            'allowBackorder' => ['nullable', 'boolean'],

            'isActive' => ['nullable', 'boolean'],
            'isFeatured' => ['nullable', 'boolean'],
            'isNew' => ['nullable', 'boolean'],
            'isBestSeller' => ['nullable', 'boolean'],
            'isPromo' => ['nullable', 'boolean'],

            // Images: array of { url, altFr?, altAr? } — the form uploads
            // first to /uploads/product-image and then sends the resulting
            // URLs here.
            'images' => ['nullable', 'array', 'max:5'],
            'images.*.url' => ['required_with:images', 'string', 'max:1000'],
            'images.*.altFr' => ['nullable', 'string', 'max:200'],
            'images.*.altAr' => ['nullable', 'string', 'max:200'],

            // One optional product video — the form uploads to
            // /uploads/product-video and sends the resulting URL here.
            'video' => ['nullable', 'string', 'max:1000'],

            // Variants — each row is a purchasable (color, size) combo.
            // Either color_* or sizeLabel must be present.
            'variants' => ['nullable', 'array'],
            'variants.*.colorNameFr' => ['nullable', 'string', 'max:60'],
            'variants.*.colorNameAr' => ['nullable', 'string', 'max:60'],
            'variants.*.colorHex' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/'],
            'variants.*.sizeLabel' => ['nullable', 'string', 'max:32'],
            'variants.*.skuSuffix' => ['nullable', 'string', 'max:32'],
            'variants.*.priceDelta' => ['nullable', 'integer'],
            'variants.*.stock' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'nameFr.required' => 'Le nom français est requis.',
            'nameAr.required' => 'Le nom arabe est requis.',
            'categoryId.required' => 'Choisissez une catégorie.',
            'categoryId.exists' => 'Catégorie introuvable.',
            'brandId.required' => 'Choisissez une marque.',
            'brandId.exists' => 'Marque introuvable.',
            'price.required' => 'Le prix est requis.',
            'oldPrice.gte' => "L'ancien prix doit être supérieur ou égal au prix.",
            'sku.unique' => 'Ce SKU est déjà utilisé.',
            'slug.unique' => 'Ce slug est déjà utilisé.',
            'images.max' => 'Maximum 5 photos par produit.',
        ];
    }

    public function toModel(): array
    {
        $v = $this->validated();
        $nullable = fn ($x) => is_string($x) && trim($x) === '' ? null : $x;

        $slug = $nullable($v['slug'] ?? null) ?? Str::slug($v['nameFr']).'-'.Str::lower(Str::random(4));
        $sku = $nullable($v['sku'] ?? null) ?? 'BIN-'.strtoupper(Str::random(6));

        return [
            'slug' => $slug,
            'sku' => $sku,
            'name_fr' => $v['nameFr'],
            'name_ar' => $v['nameAr'],
            'description_short_fr' => $nullable($v['descriptionShortFr'] ?? null),
            'description_short_ar' => $nullable($v['descriptionShortAr'] ?? null),
            'description_fr' => $nullable($v['descriptionFr'] ?? null),
            'description_ar' => $nullable($v['descriptionAr'] ?? null),
            'video' => $nullable($v['video'] ?? null),

            'category_id' => (int) $v['categoryId'],
            'brand_id' => (int) $v['brandId'],

            'price' => (int) $v['price'],
            'old_price' => isset($v['oldPrice']) ? (int) $v['oldPrice'] : null,
            'stock' => $v['stock'] ?? 0,
            'low_stock_threshold' => $v['lowStockThreshold'] ?? 5,
            'track_stock' => array_key_exists('trackStock', $v) ? (bool) $v['trackStock'] : true,
            'allow_backorder' => array_key_exists('allowBackorder', $v) ? (bool) $v['allowBackorder'] : false,

            'is_active' => array_key_exists('isActive', $v) ? (bool) $v['isActive'] : true,
            'is_featured' => (bool) ($v['isFeatured'] ?? false),
            'is_new' => (bool) ($v['isNew'] ?? false),
            'is_best_seller' => (bool) ($v['isBestSeller'] ?? false),
            'is_promo' => (bool) ($v['isPromo'] ?? false),

            'published_at' => now(),
        ];
    }
}
