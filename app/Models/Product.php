<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'slug',
    'sku',
    'name_fr',
    'name_ar',
    'description_short_fr',
    'description_short_ar',
    'description_fr',
    'description_ar',
    'category_id',
    'brand_id',
    'price',
    'old_price',
    'stock',
    'low_stock_threshold',
    'track_stock',
    'allow_backorder',
    'is_active',
    'is_featured',
    'is_new',
    'is_best_seller',
    'is_promo',
    'attributes',
    'published_at',
])]
class Product extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'old_price' => 'integer',
            'stock' => 'integer',
            'low_stock_threshold' => 'integer',
            'rating' => 'float',
            'review_count' => 'integer',
            'view_count' => 'integer',
            'sold_count' => 'integer',
            'track_stock' => 'boolean',
            'allow_backorder' => 'boolean',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_new' => 'boolean',
            'is_best_seller' => 'boolean',
            'is_promo' => 'boolean',
            'attributes' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('display_order');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('display_order');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'favorites')->withTimestamps();
    }

    /**
     * Derived from stock + threshold; no DB column for it. The frontend uses
     * the same three values.
     */
    public function stockStatus(): string
    {
        if (! $this->track_stock) {
            return 'in_stock';
        }
        if ($this->stock <= 0) {
            return 'out_of_stock';
        }
        if ($this->stock <= $this->low_stock_threshold) {
            return 'low_stock';
        }
        return 'in_stock';
    }
}
