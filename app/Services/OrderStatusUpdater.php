<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStatusEntry;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for "move an order to a new status".
 *
 * Wraps the status flip, the history-entry write, and the stock reservation
 * move in one transaction so they always commit together — a half-applied
 * confirmation would leave stock permanently out of sync with order reality.
 *
 * Used by both the admin endpoint (OrderController) and the ZR Express status
 * sync (which mirrors the carrier's delivery state back onto the order), so
 * the inventory rules apply identically no matter who triggers the change.
 */
class OrderStatusUpdater
{
    /**
     * Status values that mean "stock is committed to this order" — confirmée
     * + the three fulfilment states. Every other status (pending / cancelled
     * / returned) releases stock back to the pool. Crossing the boundary in
     * either direction triggers a per-line decrement / increment.
     */
    private const RESERVED_STATUSES = ['confirmed', 'preparing', 'shipped', 'delivered'];

    /**
     * Apply $newStatus to $order, recording who/why in the history.
     *
     * Returns true when the status actually changed (and the transaction ran),
     * false when $newStatus equals the current status (no-op, no history row).
     */
    public function apply(Order $order, string $newStatus, string $by, ?string $note = null): bool
    {
        $oldStatus = $order->status;
        if ($oldStatus === $newStatus) {
            return false;
        }

        DB::transaction(function () use ($order, $newStatus, $by, $note, $oldStatus) {
            $order->update(['status' => $newStatus]);
            OrderStatusEntry::create([
                'order_id' => $order->id,
                'status' => $newStatus,
                'by' => $by,
                'note' => $note,
            ]);
            $this->adjustStockForStatusChange($order, $oldStatus, $newStatus);
        });

        return true;
    }

    public function statusReservesStock(string $status): bool
    {
        return in_array($status, self::RESERVED_STATUSES, true);
    }

    /**
     * Move stock for each line when the status flip crosses the
     * reserved↔released boundary. No-op within the same bucket (e.g.
     * preparing → shipped: both reserved → no change).
     *
     * Skips infinite-stock products (track_stock = false) and lines whose
     * product was deleted after the order (product_id null). Negative stock
     * is allowed on decrement — it signals an oversell rather than silently
     * clamping at zero. Uses save() (not ->decrement()) so the Product saving
     * hook fires and the auto-deactivate-on-zero rule still applies.
     */
    private function adjustStockForStatusChange(Order $order, string $oldStatus, string $newStatus): void
    {
        if ($oldStatus === $newStatus) {
            return;
        }
        $wasReserved = $this->statusReservesStock($oldStatus);
        $isReserved = $this->statusReservesStock($newStatus);
        if ($wasReserved === $isReserved) {
            return;
        }

        $lines = $order->lines()
            ->whereNotNull('product_id')
            ->get(['id', 'product_id', 'quantity']);

        foreach ($lines as $line) {
            $product = Product::find($line->product_id);
            if (! $product || ! $product->track_stock) {
                continue;
            }
            $qty = (int) $line->quantity;
            if ($qty <= 0) {
                continue;
            }
            $product->stock = (int) $product->stock + ($isReserved ? -$qty : $qty);
            $product->save();
        }
    }
}
