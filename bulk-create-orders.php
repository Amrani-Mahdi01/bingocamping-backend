<?php

/**
 * One-off script to seed sample orders so the admin /orders page has
 * something to display across the default 7-day window AND a tail
 * just outside it (so the date filter can be exercised too).
 *
 *   php bulk-create-orders.php
 *
 * Orders are spread across the last 10 days. Status, wilaya, products,
 * quantities, and customer names are randomized.
 */

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Wilaya;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// Bucketed spread across the last 3 months. Bias is intentional: the
// current week gets the densest coverage so the admin's default
// 7-day view is full, then the rest of the month, then the older
// 2 months — enough to exercise date filters without making the
// catalog feel artificially busy in the distant past.
const BUCKETS = [
    ['days_from' => 0,  'days_to' => 6,  'count' => 35], // this week (incl. today)
    ['days_from' => 7,  'days_to' => 29, 'count' => 45], // rest of last 30 days
    ['days_from' => 30, 'days_to' => 89, 'count' => 40], // months 2-3
];

$wilayas = Wilaya::all();
$products = Product::with('images')->where('is_active', true)->get();

// Wipe whatever's already there so we don't pile up duplicates each
// time this script runs. `order_lines` + `order_status_history`
// cascade via the foreign keys defined in the create-order-tables
// migration, so deleting `orders` is enough.
$wiped = Order::query()->delete();
echo "wiped $wiped existing orders\n\n";

if ($wilayas->isEmpty()) {
    echo "No wilayas — run WilayaSeeder first.\n";
    exit(1);
}
if ($products->isEmpty()) {
    echo "No products — run ProductSeeder first.\n";
    exit(1);
}

$statuses = ['pending', 'confirmed', 'preparing', 'shipped', 'delivered', 'cancelled'];
$firstNames = ['Yacine', 'Amine', 'Sofiane', 'Karim', 'Mehdi', 'Anis', 'Riad', 'Walid', 'Imène', 'Lina', 'Sara', 'Nadia', 'Salim', 'Bilal', 'Hakim'];
$lastNames = ['Benali', 'Bouzid', 'Hamidi', 'Khaled', 'Mahmoudi', 'Saidi', 'Boukhalfa', 'Cherif', 'Djebbar', 'Rahmani'];
$communes = ['Sétif', 'El Eulma', 'Aïn Arnat', 'Bougaa', 'Aïn Oulmène', 'Béni Ourtilane'];

$created = 0;
$plan = [];
foreach (BUCKETS as $bucket) {
    for ($i = 0; $i < $bucket['count']; $i++) {
        $plan[] = random_int(
            $bucket['days_from'] * 24 * 60,
            $bucket['days_to'] * 24 * 60 + 23 * 60,
        );
    }
}
shuffle($plan);

foreach ($plan as $minutesAgo) {
    $product = $products->random();
    $qty = random_int(1, 3);
    $variant = $product->variants->first();
    $unit = (int) ($product->price + ($variant?->price_delta ?? 0));
    $subtotal = $unit * $qty;
    $shipping = random_int(400, 900);
    $total = $subtotal + $shipping;
    $wilaya = $wilayas->random();
    $first = $firstNames[array_rand($firstNames)];
    $last = $lastNames[array_rand($lastNames)];
    $status = $statuses[array_rand($statuses)];

    $createdAt = Carbon::now()->subMinutes($minutesAgo);

    $orderNumber = 'BIN-' . $createdAt->format('Y') . '-' . str_pad((string) ($created + 1), 5, '0', STR_PAD_LEFT);

    // `created_at` / `updated_at` aren't in Order::$fillable, so a
    // plain Order::create([...]) silently drops them and Eloquent's
    // own `updateTimestamps()` stamps "now" instead. `forceFill`
    // bypasses the mass-assignment guard AND marks the timestamps
    // dirty, which makes Laravel skip its auto-stamp because it only
    // sets created/updated when the attribute isn't already dirty.
    $order = (new Order)->forceFill([
        'order_number' => $orderNumber,
        'customer_id' => null,
        'customer_first_name' => $first,
        'customer_last_name' => $last,
        'customer_phone' => '+2136' . random_int(10000000, 99999999),
        'customer_email' => null,
        'wilaya_id' => $wilaya->id,
        'wilaya_name' => $wilaya->name_fr ?? $wilaya->name ?? ('Wilaya ' . $wilaya->id),
        'commune' => $communes[array_rand($communes)],
        'address' => 'Cité ' . Str::random(6) . ', Bât. ' . random_int(1, 30),
        'notes' => null,
        'subtotal' => $subtotal,
        'shipping_fee' => $shipping,
        'total' => $total,
        'status' => $status,
        'payment_method' => 'cod',
        'payment_status' => $status === 'delivered' ? 'paid' : 'pending',
        'customer_ip' => '41.' . random_int(100, 220) . '.' . random_int(0, 255) . '.' . random_int(1, 254),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
    $order->save();

    (new OrderLine)->forceFill([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => $product->name_fr,
        'sku' => $product->sku,
        'image' => $product->images->first()?->url,
        'variant' => $variant?->size_label,
        'quantity' => $qty,
        'unit_price' => $unit,
        'total' => $subtotal,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ])->save();

    $created++;
    echo "$orderNumber · $first $last · $status · $total DZD · " . $createdAt->toDateString() . "\n";
}

$total = Order::count();
echo "\n-- created $created orders — total in DB: $total --\n";
