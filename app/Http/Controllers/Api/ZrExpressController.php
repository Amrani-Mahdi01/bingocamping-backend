<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Jobs\SendOrderToZrExpress;
use App\Models\Commune;
use App\Models\Order;
use App\Models\OrderStatusEntry;
use App\Models\Setting;
use App\Models\Wilaya;
use App\Services\ZrExpress\ZrExpressException;
use App\Services\ZrExpress\ZrExpressService;
use App\Services\ZrExpress\ZrOrderSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-only control panel for the ZR Express delivery integration.
 *
 * Credentials live in `site_settings` as NON-public rows (is_public = false)
 * so they're never returned by the storefront's GET /api/settings — only by
 * the admin-guarded endpoints here, and the API key is always masked on read.
 *
 *  - settings / updateSettings : the admin token field + toggles (the core ask)
 *  - test                      : verify the token against ZR
 *  - syncTerritories           : map wilayas/communes → ZR territory UUIDs
 *  - syncRates                 : ZR-04 — pull delivery fees per wilaya
 *  - syncStatuses              : ZR-03 — pull parcel states onto orders
 *  - ship                      : ZR-01 — push/retry one order to ZR
 *  - label                     : ZR-05 — generate a parcel's bordereau
 */
class ZrExpressController extends Controller
{
    /** GET /api/admin/zr/settings — current config (API key masked). */
    public function settings(ZrExpressService $zr): JsonResponse
    {
        return response()->json(['data' => $this->settingsPayload($zr)]);
    }

    /**
     * PUT /api/admin/zr/settings — save token + toggles.
     * Body (camelCase): { apiKey?, tenant?, enabled, autoSend, baseUrl?, version? }
     * apiKey is only overwritten when a non-empty value is sent, so the UI can
     * leave the masked field untouched without wiping the stored token.
     */
    public function updateSettings(Request $request, ZrExpressService $zr): JsonResponse
    {
        $data = $request->validate([
            'apiKey'   => ['nullable', 'string', 'max:500'],
            'tenant'   => ['nullable', 'string', 'max:200'],
            'enabled'  => ['nullable', 'boolean'],
            'autoSend' => ['nullable', 'boolean'],
            'baseUrl'  => ['nullable', 'string', 'url', 'max:200'],
            'version'  => ['nullable', 'string', 'max:20'],
        ]);

        $keys = config('zrexpress.keys');

        // Secret — only replace when a fresh value is actually provided.
        if (array_key_exists('apiKey', $data) && filled($data['apiKey'])) {
            Setting::set($keys['api_key'], trim($data['apiKey']), isPublic: false);
        }

        // Optional string settings: empty string clears them.
        foreach (['tenant' => 'tenant', 'baseUrl' => 'base_url', 'version' => 'version'] as $field => $keyName) {
            if (array_key_exists($field, $data)) {
                $value = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
                Setting::set($keys[$keyName], $value !== '' ? $value : null, isPublic: false);
            }
        }

        // Booleans stored as '1' / '0'.
        if (array_key_exists('enabled', $data)) {
            Setting::set($keys['enabled'], $data['enabled'] ? '1' : '0', isPublic: false);
        }
        if (array_key_exists('autoSend', $data)) {
            Setting::set($keys['auto_send'], $data['autoSend'] ? '1' : '0', isPublic: false);
        }

        return response()->json(['data' => $this->settingsPayload($zr)]);
    }

    /** POST /api/admin/zr/test — verify the configured token against ZR. */
    public function test(ZrExpressService $zr): JsonResponse
    {
        try {
            $profile = $zr->testConnection();
        } catch (ZrExpressException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'context' => $e->context,
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Connexion à ZR Express réussie.',
            'profile' => $profile,
        ]);
    }

    /**
     * POST /api/admin/zr/sync-territories — IMPORT ZR Express's full territory
     * tree into the local wilayas + communes tables. ZR is the source of truth:
     * we pull every wilaya + commune (names, IDs) and the per-wilaya delivery
     * prices in two API calls, upsert wilayas (zr_territory_id + prices), and
     * REBUILD each wilaya's communes straight from ZR. Every commune therefore
     * carries a zr_district_id by construction — parcel creation can never fail
     * with "commune not linked", and there are no fragile name-matching bugs.
     * Wilaya display names are left as-is (the local accented spellings are
     * nicer than ZR's ASCII ones); only mapping + prices are updated.
     */
    public function syncTerritories(ZrExpressService $zr): JsonResponse
    {
        try {
            $all   = $zr->searchTerritories(null, 5000); // whole tree, one page
            $rates = $zr->getRates();
        } catch (ZrExpressException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        // Which communes host a ZR pickup point (stop desk). Each visible
        // pickup-point hub names the commune it sits in via
        // address.districtTerritoryId, which equals a commune's zr_district_id.
        // Best-effort: if the hubs call fails we still import territories, just
        // with every stop-desk flag left false.
        $stopDeskDistricts = [];
        try {
            foreach ($zr->searchHubs() as $h) {
                if (empty($h['isPickupPoint']) || empty($h['isVisible'])) {
                    continue; // hidden sorting center, not a customer desk
                }
                $districtId = $h['address']['districtTerritoryId'] ?? null;
                if ($districtId) {
                    $stopDeskDistricts[$districtId] = true;
                }
            }
        } catch (ZrExpressException $e) {
            // Leave $stopDeskDistricts empty — flags default to false.
        }

        // Split ZR territories into wilayas + communes grouped by parent.
        $zrWilayas = [];
        $communesByParent = [];
        foreach ($all as $t) {
            $level = $t['level'] ?? null;
            if ($level === 'wilaya' && isset($t['code'], $t['id'])) {
                $zrWilayas[] = $t;
            } elseif ($level === 'commune' && ! empty($t['parentId'])) {
                $communesByParent[$t['parentId']][] = $t;
            }
        }

        // Local wilaya ids ZR actually serves — everything else is pruned so
        // the dashboard only ever holds ZR's wilayas/communes.
        $keepIds = array_map(
            fn ($zw) => str_pad((string) (int) $zw['code'], 2, '0', STR_PAD_LEFT),
            $zrWilayas
        );

        $wilayasImported = 0;
        $communesImported = 0;
        $wilayasRemoved = 0;

        \Illuminate\Support\Facades\DB::transaction(function () use (
            $zrWilayas, $communesByParent, $rates, $keepIds, $stopDeskDistricts,
            &$wilayasImported, &$communesImported, &$wilayasRemoved
        ) {
            foreach ($zrWilayas as $zw) {
                $code = (int) $zw['code'];
                $localId = str_pad((string) $code, 2, '0', STR_PAD_LEFT);

                // Upsert the wilaya: set ZR territory id + prices. Existing
                // wilayas keep their (nicer) names/region/delivery_days.
                $wilaya = Wilaya::find($localId);
                if (! $wilaya) {
                    $wilaya = new Wilaya([
                        'name_fr' => $zw['name'] ?? ('Wilaya '.$code),
                        'name_ar' => $zw['nameArabic'] ?? '',
                        'region' => 'Centre',
                        'delivery_days' => 3,
                        'shipping_price' => 0,
                        'stop_desk_price' => 0,
                    ]);
                    $wilaya->id = $localId;
                    $wilaya->code = $localId;
                }
                $wilaya->zr_territory_id = $zw['id'];
                $wilaya->save();
                $wilayasImported++;

                // Rebuild this wilaya's communes straight from ZR.
                Commune::where('wilaya_id', $localId)->delete();
                $rows = [];
                $seen = [];
                foreach ($communesByParent[$zw['id']] ?? [] as $d) {
                    if (empty($d['id'])) {
                        continue;
                    }
                    // code must be unique per wilaya; fall back to the id slice.
                    $cCode = (string) ($d['code'] ?? '');
                    if ($cCode === '' || isset($seen[$cCode])) {
                        $cCode = substr((string) $d['id'], 0, 16);
                    }
                    if (isset($seen[$cCode])) {
                        continue;
                    }
                    $seen[$cCode] = true;
                    $rows[] = [
                        'wilaya_id' => $localId,
                        'code' => $cCode,
                        'name_fr' => mb_substr((string) ($d['name'] ?? ''), 0, 250) ?: 'Commune',
                        'name_ar' => mb_substr((string) ($d['nameArabic'] ?? ''), 0, 250),
                        'zr_district_id' => $d['id'],
                        'has_stop_desk' => isset($stopDeskDistricts[$d['id']]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    Commune::insert($chunk);
                }
                $communesImported += count($rows);
            }

            // Prices — communes are imported now, so the commune→wilaya lookup
            // works: wilaya-level rows price the other wilayas; the supplier's
            // OWN wilaya (no wilaya-level row) is priced from its local commune
            // rows.
            foreach ($this->wilayaPricesFromRates($rates) as $wid => [$home, $stop]) {
                $changes = [];
                if ($home !== null) {
                    $changes['shipping_price'] = (int) round($home);
                }
                if ($stop !== null) {
                    $changes['stop_desk_price'] = (int) round($stop);
                }
                if ($changes) {
                    Wilaya::where('id', $wid)->update($changes);
                }
            }

            // Prune wilayas ZR doesn't serve — but never one that already has
            // orders (orders→wilayas is restrictOnDelete; checking first avoids
            // throwing and rolling back the whole import). Communes cascade.
            foreach (Wilaya::whereNotIn('id', $keepIds)->pluck('id') as $sid) {
                if (! Order::where('wilaya_id', $sid)->exists()) {
                    Wilaya::where('id', $sid)->delete();
                    $wilayasRemoved++;
                }
            }
        });

        return response()->json([
            'ok' => true,
            'message' => "Import ZR terminé : {$wilayasImported} wilayas et {$communesImported} communes importées depuis ZR Express"
                . ($wilayasRemoved ? " ({$wilayasRemoved} wilaya(s) hors ZR retirée(s))." : "."),
            'wilayasMatched' => $wilayasImported,
            'wilayasTotal' => Wilaya::count(),
            'communesMatched' => Commune::whereNotNull('zr_district_id')->count(),
            'communesTotal' => Commune::count(),
            'stopDeskCommunes' => Commune::where('has_stop_desk', true)->count(),
        ]);
    }

    /**
     * POST /api/admin/zr/sync-rates — ZR-04. Pull effective delivery prices
     * and write them onto each wilaya (home → shipping_price, pickup →
     * stop_desk_price). Checkout keeps reading the local column, so fees come
     * from ZR without making checkout depend on ZR uptime.
     */
    public function syncRates(ZrExpressService $zr): JsonResponse
    {
        try {
            $rates = $zr->getRates();
        } catch (ZrExpressException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $updated = 0;
        foreach ($this->wilayaPricesFromRates($rates) as $wid => [$home, $stopdesk]) {
            $wilaya = Wilaya::find($wid);
            if (! $wilaya) {
                continue;
            }
            $changes = [];
            if ($home !== null) {
                $changes['shipping_price'] = (int) round($home);
            }
            if ($stopdesk !== null) {
                $changes['stop_desk_price'] = (int) round($stopdesk);
            }
            if ($changes) {
                $wilaya->update($changes);
                $updated++;
            }
        }

        return response()->json([
            'ok' => true,
            'message' => "Tarifs de livraison synchronisés pour {$updated} wilaya(s).",
            'updated' => $updated,
        ]);
    }

    /**
     * Build [localWilayaId => [home, stopdesk]] from the ZR rates feed.
     *
     * ZR prices are relative to the supplier's hub: wilaya-level rows give the
     * price to each OTHER wilaya, while the supplier's OWN wilaya has no
     * wilaya-level row — ZR prices it per local commune instead. So we read the
     * wilaya-level rows directly, then fill in the supplier's own wilaya from
     * its commune-level rows (grouped to the wilaya via the communes table,
     * taking the most common price). Requires communes to be imported already.
     */
    private function wilayaPricesFromRates(array $rates): array
    {
        $byId = [];
        $communeRows = [];
        foreach ($rates as $row) {
            $level = $row['toTerritoryLevel'] ?? '';
            if ($level === 'wilaya' && isset($row['toTerritoryCode'])) {
                $id = str_pad((string) (int) $row['toTerritoryCode'], 2, '0', STR_PAD_LEFT);
                $byId[$id] = $this->extractRates($row['deliveryPrices'] ?? []);
            } elseif ($level === 'commune' && ! empty($row['toTerritoryId'])) {
                $communeRows[] = $row;
            }
        }

        // Group commune-level prices by their wilaya (the supplier's own).
        $communeByWilaya = [];
        foreach ($communeRows as $row) {
            $wid = Commune::where('zr_district_id', $row['toTerritoryId'])->value('wilaya_id');
            if ($wid !== null) {
                $communeByWilaya[$wid][] = $this->extractRates($row['deliveryPrices'] ?? []);
            }
        }
        foreach ($communeByWilaya as $wid => $prices) {
            if (isset($byId[$wid])) {
                continue; // a wilaya-level row already covers this wilaya
            }
            $byId[$wid] = [
                $this->modePrice(array_column($prices, 0)),
                $this->modePrice(array_column($prices, 1)),
            ];
        }

        return $byId;
    }

    /** Most common non-null value in a list (ties → first seen), or null. */
    private function modePrice(array $values): ?float
    {
        $counts = [];
        foreach ($values as $v) {
            if ($v === null) {
                continue;
            }
            $k = (string) $v;
            $counts[$k] = ($counts[$k] ?? 0) + 1;
        }
        if ($counts === []) {
            return null;
        }
        arsort($counts);
        return (float) array_key_first($counts);
    }

    /** POST /api/admin/zr/sync-statuses — ZR-03, manual trigger. */
    public function syncStatuses(ZrOrderSync $sync, ZrExpressService $zr): JsonResponse
    {
        if (! $zr->enabled()) {
            return response()->json(['ok' => false, 'message' => 'Intégration ZR Express désactivée.'], 422);
        }
        $summary = $sync->syncActive();
        return response()->json([
            'ok' => true,
            'message' => "Synchronisation terminée : {$summary['updated']} mise(s) à jour sur {$summary['checked']} colis ({$summary['errors']} erreur(s)).",
            ...$summary,
        ]);
    }

    /**
     * POST /api/admin/orders/{order}/zr-sync — pull THIS order's current state
     * from ZR now (the per-order "Rafraîchir le statut ZR" button). Same
     * forward-only logic as the scheduled sync, just scoped to one order.
     */
    public function syncOrder(Order $order, ZrOrderSync $sync, ZrExpressService $zr): JsonResponse
    {
        if (! $zr->enabled()) {
            return response()->json(['ok' => false, 'message' => 'Intégration ZR Express désactivée.'], 422);
        }
        if (! $order->zr_parcel_id) {
            return response()->json(['ok' => false, 'message' => "Cette commande n'a pas de colis ZR Express."], 422);
        }

        $result = $sync->syncOne($order);
        if (! empty($result['error'])) {
            return response()->json(['ok' => false, 'message' => $result['error']], 422);
        }

        $order->refresh();
        return response()->json([
            'ok' => true,
            'message' => 'Statut ZR Express synchronisé.',
            'order' => new OrderResource($order->load(['lines.variantRef', 'statusHistory', 'callAttempts'])),
        ]);
    }

    /**
     * POST /api/admin/orders/{order}/ship — ZR-01 manual push / retry.
     * Runs the send job synchronously so the admin gets an immediate result.
     */
    public function ship(Order $order, ZrExpressService $zr): JsonResponse
    {
        if (! $zr->enabled()) {
            return response()->json(['ok' => false, 'message' => 'Intégration ZR Express désactivée.'], 422);
        }
        if ($order->zr_parcel_id) {
            return response()->json([
                'ok' => true,
                'message' => 'Cette commande est déjà chez ZR Express.',
                'trackingNumber' => $order->tracking_number,
            ]);
        }

        SendOrderToZrExpress::dispatchSync($order->id);
        $order->refresh();

        if (! $order->zr_parcel_id) {
            return response()->json([
                'ok' => false,
                'message' => $order->zr_last_error ?: "Échec de l'envoi à ZR Express.",
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Commande envoyée à ZR Express.',
            'trackingNumber' => $order->tracking_number,
            'order' => new OrderResource($order->load(['lines.variantRef', 'statusHistory', 'callAttempts'])),
        ]);
    }

    /**
     * POST /api/admin/orders/{order}/zr-detach — clear an order's ZR Express
     * linkage (parcel id, tracking, state, sync error). For when the parcel was
     * deleted/cancelled on ZR's side: the order is otherwise stuck — the status
     * sync 404s and "Envoyer" refuses to resend because a parcel id is still
     * stored. Detaching lets the admin push it to ZR again from scratch. The
     * local order STATUS is intentionally left untouched.
     */
    public function detach(Order $order): JsonResponse
    {
        if (! $order->zr_parcel_id && ! $order->tracking_number) {
            return response()->json([
                'ok' => false,
                'message' => "Cette commande n'est pas liée à ZR Express.",
            ], 422);
        }

        $order->update([
            'zr_parcel_id'    => null,
            'tracking_number' => null,
            'zr_state'        => null,
            'zr_synced_at'    => null,
            'zr_last_error'   => null,
        ]);

        OrderStatusEntry::create([
            'order_id' => $order->id,
            'status'   => $order->status,
            'by'       => 'ZR Express',
            'note'     => 'Commande détachée de ZR Express',
            'at'       => now(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Commande détachée de ZR Express.',
            'order' => new OrderResource($order->load(['lines.variantRef', 'statusHistory', 'callAttempts'])),
        ]);
    }

    /**
     * GET /api/admin/orders/{order}/label — ZR-05. Returns the URL(s) of the
     * generated bordereau (HTML hosted by ZR) for the admin to open/print.
     */
    public function label(Order $order, ZrExpressService $zr): JsonResponse
    {
        if (! $order->tracking_number) {
            return response()->json([
                'ok' => false,
                'message' => "Aucun numéro de suivi : envoyez d'abord la commande à ZR Express.",
            ], 422);
        }

        try {
            $result = $zr->generateLabels([$order->tracking_number]);
        } catch (ZrExpressException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $files = $result['parcelLabelFiles'] ?? [];
        $url = $files[0]['fileUrl'] ?? null;
        if (! $url) {
            return response()->json([
                'ok' => false,
                'message' => 'ZR Express n’a pas pu générer le bordereau.',
                'failed' => $result['failedTrackingNumbers'] ?? [],
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'url' => $url,
            'trackingNumber' => $order->tracking_number,
        ]);
    }

    // ---- helpers ------------------------------------------------------------

    private function settingsPayload(ZrExpressService $zr): array
    {
        $keys = config('zrexpress.keys');
        $apiKey = Setting::get($keys['api_key']);

        return [
            'enabled' => Setting::get($keys['enabled']) === '1',
            'autoSend' => Setting::get($keys['auto_send']) === '1',
            'tenant' => Setting::get($keys['tenant']),
            'baseUrl' => Setting::get($keys['base_url']) ?: config('zrexpress.base_url'),
            'version' => Setting::get($keys['version']) ?: config('zrexpress.version'),
            'apiKeyConfigured' => filled($apiKey),
            'apiKeyMasked' => $this->maskKey($apiKey),
            // Mapping coverage so the admin can see whether a territory sync
            // is still needed before parcels can be created.
            'mapping' => [
                'wilayasMapped' => Wilaya::whereNotNull('zr_territory_id')->count(),
                'wilayasTotal' => Wilaya::count(),
                'communesMapped' => Commune::whereNotNull('zr_district_id')->count(),
                'communesTotal' => Commune::count(),
            ],
        ];
    }

    private function maskKey(?string $key): ?string
    {
        if (! filled($key)) {
            return null;
        }
        $len = mb_strlen($key);
        $tail = mb_substr($key, -4);
        return str_repeat('•', max(4, min(12, $len - 4))).$tail;
    }

    /**
     * Pull the home + stopdesk price out of ZR's deliveryPrices array. The
     * deliveryType strings vary ('home' / 'pickup-point' / 'stopdesk' / null);
     * match on substrings and fall back to "first price = home" when untyped.
     */
    private function extractRates(array $deliveryPrices): array
    {
        $home = null;
        $stopdesk = null;
        foreach ($deliveryPrices as $dp) {
            $type = strtolower((string) ($dp['deliveryType'] ?? ''));
            $price = isset($dp['price']) ? (float) $dp['price'] : null;
            if ($price === null) {
                continue;
            }
            if (str_contains($type, 'home') || str_contains($type, 'domicile')) {
                $home ??= $price;
            } elseif (str_contains($type, 'pickup') || str_contains($type, 'stop') || str_contains($type, 'desk') || str_contains($type, 'bureau')) {
                $stopdesk ??= $price;
            } elseif ($type === '' && $home === null) {
                // ONLY a genuinely untyped price falls back to home. A known
                // but irrelevant type (e.g. 'return') must be ignored — it was
                // wrongly grabbed as the home price, so home showed the return
                // fee (e.g. 200) instead of the real home rate.
                $home = $price;
            }
        }
        return [$home, $stopdesk];
    }

    /**
     * Normalise a place name for fuzzy matching (lowercase, accents folded).
     * Uses an explicit strtr map rather than iconv('//TRANSLIT') — the latter
     * is unreliable on Windows (it renders "é" as "'e", so "Sétif" became
     * "s etif" and never matched ZR's "Setif").
     */
    private function normalise(?string $name): string
    {
        $name = mb_strtolower((string) $name, 'UTF-8');
        $name = strtr($name, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
        ]);
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name);
        return trim((string) $name);
    }
}
