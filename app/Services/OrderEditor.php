<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderStatusEntry;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Wilaya;
use App\Models\ZrHub;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Applies an admin's manual edit to an existing order — line items, prices,
 * quantities, delivery destination and fee, customer details — in one
 * transaction.
 *
 * Stock is only moved when the order is CURRENTLY in a stock-reserving status
 * (confirmed / preparing / shipped / delivered). For pending / cancelled /
 * returned orders no stock is held, so re-writing their lines never touches
 * inventory. The reserved-order path releases the old line set's reservation
 * and re-reserves the new one, reusing the same per-variant flooring +
 * product-total resync rules as {@see OrderStatusUpdater}.
 */
class OrderEditor
{
    public function __construct(private OrderStatusUpdater $status)
    {
    }

    /**
     * @param array{
     *   customer: array{firstName:string,lastName:string,phone:string,email?:?string},
     *   shipping: array{wilayaId:string,commune:string,address?:?string,deliveryType?:string,notes?:?string},
     *   shippingFee?: ?int,
     *   lines: array<int,array<string,mixed>>
     * } $data  Already validated by the controller.
     */
    public function apply(Order $order, array $data, string $by): void
    {
        DB::transaction(function () use ($order, $data, $by) {
            $reserved = $this->status->statusReservesStock($order->status);

            // Snapshot the reservation the current lines hold, BEFORE we wipe them.
            $oldMap = $this->reservationMap($order->lines()->get());

            // Resolve the (possibly new) wilaya for the fee + name snapshot.
            $wilaya = Wilaya::findOrFail($data['shipping']['wilayaId']);
            $deliveryType = (($data['shipping']['deliveryType'] ?? 'home') === 'stopdesk')
                ? 'stopdesk'
                : 'home';

            // Stop-desk edit: honour the exact chosen desk and derive the
            // commune from it. Home orders keep the entered commune / no desk.
            $stopDeskHub = null;
            if ($deliveryType === 'stopdesk' && ! empty($data['shipping']['stopDeskId'])) {
                $stopDeskHub = ZrHub::where('id', $data['shipping']['stopDeskId'])
                    ->where('wilaya_id', $wilaya->id)
                    ->first();
            }
            $communeName = $stopDeskHub
                ? (string) ($stopDeskHub->commune_name ?: ($data['shipping']['commune'] ?? $order->commune))
                : trim((string) ($data['shipping']['commune'] ?? ''));

            $stopDesk = (int) $wilaya->stop_desk_price;
            $autoFee = ($deliveryType === 'stopdesk' && $stopDesk > 0)
                ? $stopDesk
                : (int) $wilaya->shipping_price;
            // A supplied shippingFee is an explicit admin override; otherwise
            // fall back to the wilaya's home/stop-desk price.
            $shippingFee = array_key_exists('shippingFee', $data) && $data['shippingFee'] !== null
                ? max(0, (int) $data['shippingFee'])
                : $autoFee;

            // Roll back sold_count for the outgoing lines (query-builder update
            // so the Product saving hook doesn't fire on a non-stock change).
            foreach ($order->lines()->get() as $old) {
                if ($old->product_id) {
                    Product::whereKey($old->product_id)->decrement('sold_count', (int) $old->quantity);
                }
            }
            $order->lines()->delete();

            // Recreate the line set from the payload, recomputing every total
            // server-side — the client's line/subtotal figures are never trusted.
            $subtotal = 0;
            foreach ($data['lines'] as $row) {
                $productId = $row['productId'] ?? null;
                $variantId = $row['variantId'] ?? null;
                // Honour a variant id only when it truly belongs to the product,
                // otherwise the wrong variant's stock would move.
                if ($productId && $variantId) {
                    $belongs = ProductVariant::whereKey($variantId)
                        ->where('product_id', $productId)
                        ->exists();
                    if (! $belongs) {
                        $variantId = null;
                    }
                }

                $qty = max(1, (int) $row['quantity']);
                $unit = max(0, (int) $row['unitPrice']);
                $lineTotal = $unit * $qty;
                $subtotal += $lineTotal;

                OrderLine::create([
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'product_name' => $row['productName'],
                    'sku' => $row['sku'],
                    'image' => $row['image'] ?? null,
                    'variant' => $row['variant'] ?? null,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'total' => $lineTotal,
                ]);

                if ($productId) {
                    Product::whereKey($productId)->increment('sold_count', $qty);
                }
            }

            // Move stock only if the order currently reserves it.
            if ($reserved) {
                $newMap = $this->reservationMap($order->lines()->get());
                $this->applyStockDelta($oldMap, $newMap);
            }

            $order->update([
                'customer_first_name' => trim($data['customer']['firstName']),
                'customer_last_name' => trim($data['customer']['lastName']),
                'customer_phone' => trim($data['customer']['phone']),
                'customer_email' => $data['customer']['email'] ?? null,
                'wilaya_id' => $wilaya->id,
                'wilaya_name' => $wilaya->name_fr,
                'commune' => $communeName,
                'address' => $data['shipping']['address'] ?? null,
                'delivery_type' => $deliveryType,
                'zr_hub_id' => $deliveryType === 'stopdesk'
                    ? ($stopDeskHub?->id ?? $order->zr_hub_id)
                    : null,
                'notes' => $data['shipping']['notes'] ?? null,
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'total' => $subtotal + $shippingFee,
            ]);

            OrderStatusEntry::create([
                'order_id' => $order->id,
                'status' => $order->status,
                'by' => $by,
                'note' => 'Commande modifiée par l’administrateur',
            ]);
        });
    }

    /**
     * Collapse a line collection into [key => ['product_id','variant_id','qty']].
     * Variant lines key on the variant, simple lines on the product; lines with
     * no product (deleted product) hold no live stock and are skipped.
     */
    private function reservationMap(Collection $lines): array
    {
        $map = [];
        foreach ($lines as $line) {
            if (! $line->product_id) {
                continue;
            }
            $qty = (int) $line->quantity;
            if ($qty <= 0) {
                continue;
            }
            $key = $line->variant_id ? 'v:'.$line->variant_id : 'p:'.$line->product_id;
            if (! isset($map[$key])) {
                $map[$key] = [
                    'product_id' => (int) $line->product_id,
                    'variant_id' => $line->variant_id ? (int) $line->variant_id : null,
                    'qty' => 0,
                ];
            }
            $map[$key]['qty'] += $qty;
        }

        return $map;
    }

    /**
     * Apply a stock delta of (oldQty − newQty) per product/variant: releasing
     * what the old lines held and reserving what the new ones need. Mirrors
     * {@see OrderStatusUpdater}: variant stock floors at 0 and its product total
     * re-syncs to the sum of its variants; a simple product's total moves
     * directly and may go negative to flag an oversell. Untracked products and
     * deleted variants/products are skipped.
     */
    private function applyStockDelta(array $oldMap, array $newMap): void
    {
        $keys = array_unique(array_merge(array_keys($oldMap), array_keys($newMap)));
        $productsToResync = [];

        foreach ($keys as $key) {
            $old = $oldMap[$key]['qty'] ?? 0;
            $new = $newMap[$key]['qty'] ?? 0;
            $delta = $old - $new; // release the old reservation, take the new
            if ($delta === 0) {
                continue;
            }
            $meta = $newMap[$key] ?? $oldMap[$key];

            if ($meta['variant_id']) {
                $variant = ProductVariant::find($meta['variant_id']);
                if (! $variant) {
                    continue;
                }
                $product = Product::find($variant->product_id);
                if (! $product || ! $product->track_stock) {
                    continue;
                }
                $variant->stock = max(0, (int) $variant->stock + $delta);
                $variant->save();
                $productsToResync[$variant->product_id] = true;
                continue;
            }

            $product = Product::find($meta['product_id']);
            if (! $product || ! $product->track_stock) {
                continue;
            }
            $product->stock = (int) $product->stock + $delta;
            $product->save();
        }

        foreach (array_keys($productsToResync) as $productId) {
            $product = Product::find($productId);
            if (! $product) {
                continue;
            }
            $product->stock = (int) $product->variants()->sum('stock');
            $product->save();
        }
    }
}
