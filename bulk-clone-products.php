<?php

/**
 * One-off script to bulk-clone existing products so the admin
 * pagination has more than one page of rows to show.
 *
 * Each existing product is cloned `CLONES_PER_PRODUCT` times. The
 * clones reuse the original's category/brand/price/images but get a
 * new `sku` (-CLN-N suffix) and `slug` (-cln-N suffix) so they don't
 * collide with the originals.
 *
 *   php bulk-clone-products.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;
use App\Models\ProductImage;

const CLONES_PER_PRODUCT = 2;

$originals = Product::with('images')
    ->where('sku', 'not like', '%-CLN-%')
    ->get();

if ($originals->isEmpty()) {
    echo "No original products found.\n";
    exit(1);
}

$created = 0;
foreach ($originals as $orig) {
    for ($i = 1; $i <= CLONES_PER_PRODUCT; $i++) {
        $sku = $orig->sku . '-CLN-' . $i;
        $slug = $orig->slug . '-cln-' . $i;

        if (Product::where('sku', $sku)->exists()) {
            echo "skip (exists): $sku\n";
            continue;
        }

        $clone = $orig->replicate(['view_count', 'sold_count']);
        $clone->sku = $sku;
        $clone->slug = $slug;
        $clone->name_fr = $orig->name_fr . " · variant $i";
        $clone->name_ar = $orig->name_ar ? $orig->name_ar . " · " . $i : null;
        $clone->save();

        foreach ($orig->images as $img) {
            ProductImage::create([
                'product_id' => $clone->id,
                'url' => $img->url,
                'sort_order' => $img->sort_order,
            ]);
        }

        $created++;
        echo "created: $sku\n";
    }
}

$total = Product::count();
echo "\n-- done — created $created clones, catalog now has $total products --\n";
