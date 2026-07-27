<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'product_id',
    'color_name_fr',
    'color_name_ar',
    'color_hex',
    'color_hex2',
    'size_label',
    'sku_suffix',
    'price_delta',
    'stock',
    'display_order',
])]
class ProductVariant extends Model
{
    protected function casts(): array
    {
        return [
            'price_delta' => 'integer',
            'stock' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
