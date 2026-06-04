<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderStatusEntry;
use App\Services\ZrExpress\ZrExpressException;
use App\Services\ZrExpress\ZrExpressService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ZR-01 / ZR-02 — push a confirmed order to ZR Express and record the parcel.
 *
 * Two ZR calls: POST /parcels (create) returns only the parcel UUID, then
 * GET /parcels/{id} fetches the human tracking number + initial state. On
 * success we store zr_parcel_id + tracking_number; on failure we stash the
 * message in zr_last_error so the admin can see and retry from the dashboard.
 *
 * Carries the order id (not the model) so the row is always re-read fresh at
 * run time. Idempotent: skips orders that already have a parcel.
 */
class SendOrderToZrExpress implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Seconds between retries — only transient (5xx/network) failures retry. */
    public array $backoff = [30, 120];

    public function __construct(public int $orderId)
    {
    }

    public function handle(ZrExpressService $zr): void
    {
        $order = Order::with('lines')->find($this->orderId);
        if (! $order) {
            return;
        }

        // Re-check at run time: the integration may have been disabled, or the
        // order already pushed (e.g. manual send raced the auto-send).
        if (! $zr->enabled() || $order->zr_parcel_id !== null) {
            return;
        }

        try {
            $created = $zr->createParcel($order);
            $parcelId = $created['id'] ?? $created['parcelId'] ?? null;
            if (! is_string($parcelId) || $parcelId === '') {
                throw new ZrExpressException(
                    "Réponse ZR Express inattendue : identifiant de colis manquant.",
                    ['body' => $created],
                );
            }

            // Second call: the create response carries only the UUID, so fetch
            // the parcel to read its tracking number + initial state.
            $tracking = null;
            $state = null;
            try {
                $parcel = $zr->getParcel($parcelId);
                $tracking = $parcel['trackingNumber'] ?? null;
                $state = $zr->extractStateSlug($parcel['state'] ?? null);
            } catch (ZrExpressException $e) {
                // The parcel exists; we just couldn't read the tracking number
                // yet. The status-sync job will backfill it later. Don't fail.
                Log::warning('ZR Express: parcel created but tracking fetch failed', [
                    'order_id' => $order->id,
                    'parcel_id' => $parcelId,
                    'error' => $e->getMessage(),
                ]);
            }

            $order->update([
                'zr_parcel_id' => $parcelId,
                'tracking_number' => $tracking ?: $order->tracking_number,
                'zr_state' => $state,
                'zr_synced_at' => now(),
                'zr_last_error' => null,
            ]);

            OrderStatusEntry::create([
                'order_id' => $order->id,
                'status' => $order->status,
                'by' => 'ZR Express',
                'note' => $tracking
                    ? "Colis créé chez ZR Express — suivi {$tracking}"
                    : 'Colis créé chez ZR Express',
            ]);
        } catch (ZrExpressException $e) {
            $order->update([
                'zr_last_error' => mb_substr($e->getMessage(), 0, 500),
                'zr_synced_at' => now(),
            ]);

            // Retry only transient failures (network error = status 0, or a
            // 5xx). Config/validation errors (missing mapping, bad request)
            // won't fix themselves on retry — fail fast so the admin acts.
            $status = (int) ($e->context['status'] ?? 0);
            $transient = $status === 0 || $status >= 500;
            if ($transient && $this->attempts() < $this->tries) {
                throw $e;
            }

            Log::warning('ZR Express: parcel creation failed (no retry)', [
                'order_id' => $order->id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
