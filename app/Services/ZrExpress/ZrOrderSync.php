<?php

namespace App\Services\ZrExpress;

use App\Models\Order;
use App\Services\OrderStatusUpdater;

/**
 * ZR-03 — pull each parcel's current state from ZR Express and mirror it onto
 * the local order status. Shared by the scheduled `zr:sync-statuses` command
 * and the admin "Synchroniser maintenant" button so both behave identically.
 *
 * Forward-only along the fulfilment chain (pending → confirmed → preparing →
 * shipped → delivered) so a transient out-of-order ZR reading can't downgrade
 * an order — except returns/cancellations, which are always honoured.
 */
class ZrOrderSync
{
    /** Local-status ordering for the forward-only guard. */
    private const RANK = [
        'pending' => 0,
        'confirmed' => 1,
        'preparing' => 2,
        'shipped' => 3,
        'delivered' => 4,
    ];

    public function __construct(
        private readonly ZrExpressService $zr,
        private readonly OrderStatusUpdater $updater,
    ) {
    }

    /**
     * Sync every order that has a ZR parcel and isn't already in a terminal
     * local state. Returns a summary { checked, updated, errors, details[] }.
     */
    public function syncActive(int $limit = 500): array
    {
        $orders = Order::query()
            ->whereNotNull('zr_parcel_id')
            // cancelled/returned are done; keep delivered so post-delivery
            // returns are still captured.
            ->whereNotIn('status', ['cancelled', 'returned'])
            ->orderBy('zr_synced_at')
            ->limit($limit)
            ->get();

        $summary = ['checked' => 0, 'updated' => 0, 'errors' => 0, 'details' => []];

        foreach ($orders as $order) {
            $result = $this->syncOne($order);
            $summary['checked']++;
            if (! empty($result['error'])) {
                $summary['errors']++;
            } elseif (! empty($result['changed'])) {
                $summary['updated']++;
            }
            $summary['details'][] = $result;
        }

        return $summary;
    }

    /**
     * Refresh one order from its ZR parcel. Backfills tracking number, records
     * the raw ZR state, and applies the mapped status transition when allowed.
     */
    public function syncOne(Order $order): array
    {
        $result = [
            'orderNumber' => $order->order_number,
            'changed' => false,
            'status' => $order->status,
        ];

        if (! $order->zr_parcel_id) {
            $result['error'] = 'Commande sans colis ZR.';
            return $result;
        }

        try {
            $parcel = $this->zr->getParcel($order->zr_parcel_id);
        } catch (ZrExpressException $e) {
            $order->update([
                'zr_last_error' => mb_substr($e->getMessage(), 0, 500),
                'zr_synced_at' => now(),
            ]);
            $result['error'] = $e->getMessage();
            return $result;
        }

        $slug = $this->zr->extractStateSlug($parcel['state'] ?? null);
        $tracking = $parcel['trackingNumber'] ?? null;
        $mapped = $this->zr->mapState($parcel['state'] ?? null);

        $updates = [
            'zr_state' => $slug,
            'zr_synced_at' => now(),
            'zr_last_error' => null,
        ];
        if ($tracking && ! $order->tracking_number) {
            $updates['tracking_number'] = $tracking;
        }
        $order->update($updates);

        $result['zrState'] = $slug;
        $result['mappedStatus'] = $mapped;

        if ($mapped && $mapped !== $order->status && $this->mayTransition($order->status, $mapped)) {
            $note = "Statut synchronisé depuis ZR Express".($slug ? " ({$slug})" : '');
            $result['changed'] = $this->updater->apply($order, $mapped, 'ZR Express', $note);
            $result['status'] = $mapped;
        }

        return $result;
    }

    /**
     * Allow a transition when it moves forward along the fulfilment chain, or
     * when the target is a terminal return/cancellation (which can legitimately
     * follow any state). Blocks accidental downgrades from transient readings.
     */
    private function mayTransition(string $from, string $to): bool
    {
        if (in_array($to, ['returned', 'cancelled'], true)) {
            return true;
        }
        $fromRank = self::RANK[$from] ?? -1;
        $toRank = self::RANK[$to] ?? -1;
        return $toRank > $fromRank;
    }
}
