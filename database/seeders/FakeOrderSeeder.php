<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderStatusEntry;
use App\Models\Product;
use App\Models\Wilaya;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Dev-only seeder: generates a batch of realistic fake orders so the admin
 * order-details screens have data to work with. Each order snapshots product
 * name/sku/image/price like a real checkout, spans a mix of statuses, and
 * carries a matching status history. Safe to run repeatedly — it appends.
 *
 *   php artisan db:seed --class=FakeOrderSeeder
 */
class FakeOrderSeeder extends Seeder
{
    /** Final status for each generated order — drives the whole batch. */
    private const PLAN = [
        'pending', 'pending',
        'confirmed', 'confirmed',
        'preparing',
        'shipped', 'shipped',
        'delivered', 'delivered', 'delivered',
        'cancelled',
        'returned',
    ];

    /** Full progression leading up to each final status. */
    private const FLOW = [
        'pending'   => ['pending'],
        'confirmed' => ['pending', 'confirmed'],
        'preparing' => ['pending', 'confirmed', 'preparing'],
        'shipped'   => ['pending', 'confirmed', 'preparing', 'shipped'],
        'delivered' => ['pending', 'confirmed', 'preparing', 'shipped', 'delivered'],
        'cancelled' => ['pending', 'cancelled'],
        'returned'  => ['pending', 'confirmed', 'preparing', 'shipped', 'delivered', 'returned'],
    ];

    private const CUSTOMERS = [
        ['Yacine', 'Benali',    '0550123456', 'yacine.benali@gmail.com', '16', 'Bab El Oued'],
        ['Amina',  'Haddad',    '0661987654', null,                       '31', 'Bir El Djir'],
        ['Karim',  'Boudiaf',   '0770445566', 'k.boudiaf@yahoo.fr',       '25', 'El Khroub'],
        ['Sofia',  'Mansouri',  '0555778899', null,                       '19', 'Aïn Oulmène'],
        ['Riad',   'Cherif',    '0699112233', 'riad.cherif@gmail.com',    '09', 'Larbaâ'],
        ['Nadia',  'Belkacem',  '0540334455', null,                       '06', 'Akbou'],
        ['Sami',   'Ould Ali',  '0771556677', 's.ouldali@outlook.com',    '23', 'El Bouni'],
        ['Lina',   'Ferhat',    '0663889900', null,                       '15', 'Azazga'],
        ['Bilal',  'Saïdi',     '0558220044', 'bilal.saidi@gmail.com',    '05', 'Barika'],
        ['Meriem', 'Djaballah', '0666443322', null,                       '02', 'Chlef'],
    ];

    public function run(): void
    {
        $products = Product::with(['primaryImage', 'images', 'variants'])
            ->where('price', '>', 0)
            ->get();

        if ($products->isEmpty()) {
            $this->command?->warn('No products with a price — run ProductSeeder first.');
            return;
        }

        $year = now()->format('Y');
        $seq  = Order::whereYear('created_at', $year)->count();

        foreach (self::PLAN as $i => $finalStatus) {
            [$first, $last, $phone, $email, $wilayaId, $commune] = self::CUSTOMERS[$i % count(self::CUSTOMERS)];

            $wilaya = Wilaya::find($wilayaId) ?? Wilaya::first();
            $deliveryType = mt_rand(0, 3) === 0 ? 'stopdesk' : 'home';
            $shippingFee = ($deliveryType === 'stopdesk' && (int) $wilaya->stop_desk_price > 0)
                ? (int) $wilaya->stop_desk_price
                : (int) $wilaya->shipping_price;

            // Backdate across the last ~30 days so the list looks lived-in.
            $createdAt = now()->subDays(mt_rand(0, 29))->subHours(mt_rand(0, 23))->subMinutes(mt_rand(0, 59));

            // 1–3 distinct products per order.
            $picked = $products->random(min(mt_rand(1, 3), $products->count()));
            $lines = [];
            $subtotal = 0;

            foreach ($picked as $p) {
                $qty = mt_rand(1, 3);
                $variant = $p->variants->isNotEmpty() && mt_rand(0, 1) === 1
                    ? $p->variants->random()
                    : null;

                $unit = (int) $p->price + ($variant ? (int) $variant->price_delta : 0);
                $lineTotal = $unit * $qty;
                $subtotal += $lineTotal;

                $variantLabel = $variant
                    ? trim(collect([$variant->color_name_fr, $variant->size_label])->filter()->implode(' · '))
                    : null;

                $image = $p->primaryImage->url ?? $p->images->first()->url ?? null;

                $lines[] = [
                    'product_id' => $p->id,
                    'variant_id' => $variant?->id,
                    'product_name' => $p->name_fr,
                    'sku' => $p->sku,
                    'image' => $image,
                    'variant' => $variantLabel ?: null,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'total' => $lineTotal,
                ];
            }

            $total = $subtotal + $shippingFee;
            $seq++;

            DB::transaction(function () use (
                $finalStatus, $first, $last, $phone, $email, $wilaya, $commune,
                $deliveryType, $shippingFee, $subtotal, $total, $lines, $createdAt, $year, $seq
            ) {
                $order = Order::create([
                    'order_number' => sprintf('BIN-%s-%05d', $year, $seq),
                    'customer_id' => null,
                    'customer_first_name' => $first,
                    'customer_last_name' => $last,
                    'customer_phone' => $phone,
                    'customer_email' => $email,
                    'customer_ip' => '105.'.mt_rand(96, 111).'.'.mt_rand(0, 255).'.'.mt_rand(1, 254),
                    'wilaya_id' => $wilaya->id,
                    'wilaya_name' => $wilaya->name_fr,
                    'commune' => $commune,
                    'address' => mt_rand(1, 200).' Rue '.collect(['des Frères Bouadou', 'de l\'Indépendance', 'Didouche Mourad', 'Hassiba Ben Bouali'])->random(),
                    'delivery_type' => $deliveryType,
                    'notes' => mt_rand(0, 2) === 0 ? 'Appeler avant livraison SVP.' : null,
                    'subtotal' => $subtotal,
                    'shipping_fee' => $shippingFee,
                    'total' => $total,
                    'status' => $finalStatus,
                    'payment_method' => 'cod',
                    'payment_status' => $finalStatus === 'delivered' ? 'paid' : 'pending',
                    'tracking_number' => in_array($finalStatus, ['shipped', 'delivered', 'returned'], true)
                        ? 'ZR'.mt_rand(1000000, 9999999)
                        : null,
                    'cancellation_reason' => $finalStatus === 'cancelled'
                        ? collect(['Client injoignable', 'Annulée par le client', 'Adresse incorrecte'])->random()
                        : null,
                ]);

                foreach ($lines as $row) {
                    OrderLine::create(array_merge($row, ['order_id' => $order->id]));
                    Product::where('id', $row['product_id'])->increment('sold_count', $row['quantity']);
                }

                // Status history: spread the flow between createdAt and now.
                $flow = self::FLOW[$finalStatus];
                $span = max(1, $createdAt->diffInMinutes(now()));
                $step = intdiv($span, count($flow) + 1);
                foreach ($flow as $idx => $st) {
                    OrderStatusEntry::create([
                        'order_id' => $order->id,
                        'status' => $st,
                        'by' => $idx === 0 ? 'Système' : 'admin@bingo-camp.com',
                        'note' => $idx === 0 ? 'Commande créée par le client' : null,
                        'at' => (clone $createdAt)->addMinutes($step * $idx),
                    ]);
                }

                // Backdate the order timestamps (bypass auto-touch).
                $lastAt = (clone $createdAt)->addMinutes($step * (count($flow) - 1));
                Order::where('id', $order->id)->update([
                    'created_at' => $createdAt,
                    'updated_at' => $lastAt,
                ]);
            });
        }

        $this->command?->info(count(self::PLAN).' fake orders created (BIN-'.$year.'-'.sprintf('%05d', $seq).' last).');
    }
}
