<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Jobs\SendOrderToZrExpress;
use App\Models\Commune;
use App\Models\Order;
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
     * POST /api/admin/zr/sync-territories — resolve every local wilaya +
     * commune to its ZR territory UUID. Required before any parcel can be
     * created (ZR addresses destinations by UUID, not by Algerian code).
     */
    public function syncTerritories(ZrExpressService $zr): JsonResponse
    {
        try {
            $zrWilayas = $zr->searchTerritories([
                'field' => 'level', 'operator' => 'eq', 'value' => 'wilaya',
            ]);
        } catch (ZrExpressException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        // Index ZR wilayas by their integer code (= Algerian wilaya number).
        $byCode = [];
        foreach ($zrWilayas as $t) {
            if (isset($t['code'])) {
                $byCode[(int) $t['code']] = $t;
            }
        }

        $localWilayas = Wilaya::all();
        $wilayasMatched = 0;
        $unmatchedWilayas = [];
        $communesMatched = 0;
        $communesTotal = 0;
        $unmatchedCommunes = [];

        foreach ($localWilayas as $w) {
            $zrW = $byCode[(int) $w->code] ?? null;
            if (! $zrW || empty($zrW['id'])) {
                $unmatchedWilayas[] = $w->code.' — '.$w->name_fr;
                continue;
            }
            $w->update(['zr_territory_id' => $zrW['id']]);
            $wilayasMatched++;

            $communes = Commune::where('wilaya_id', $w->id)->get();
            if ($communes->isEmpty()) {
                continue;
            }
            $communesTotal += $communes->count();

            // Pull this wilaya's districts once, index by normalised name.
            try {
                $zrDistricts = $zr->searchTerritories([
                    'field' => 'parentId', 'operator' => 'eq', 'value' => $zrW['id'],
                ]);
            } catch (ZrExpressException $e) {
                $unmatchedCommunes[] = "(wilaya {$w->code}) ".$e->getMessage();
                continue;
            }

            $districtByName = [];
            foreach ($zrDistricts as $d) {
                if (! empty($d['name']) && ! empty($d['id'])) {
                    $districtByName[$this->normalise($d['name'])] = $d['id'];
                }
            }

            foreach ($communes as $c) {
                $id = $districtByName[$this->normalise($c->name_fr)]
                    ?? $districtByName[$this->normalise($c->name_ar)]
                    ?? null;
                if ($id) {
                    $c->update(['zr_district_id' => $id]);
                    $communesMatched++;
                } else {
                    $unmatchedCommunes[] = $w->code.' / '.$c->name_fr;
                }
            }
        }

        return response()->json([
            'ok' => true,
            'message' => "Territoires synchronisés : {$wilayasMatched}/{$localWilayas->count()} wilayas, {$communesMatched}/{$communesTotal} communes.",
            'wilayasMatched' => $wilayasMatched,
            'wilayasTotal' => $localWilayas->count(),
            'communesMatched' => $communesMatched,
            'communesTotal' => $communesTotal,
            // Cap the lists so the response stays small if many are unmatched.
            'unmatchedWilayas' => array_slice($unmatchedWilayas, 0, 60),
            'unmatchedCommunes' => array_slice($unmatchedCommunes, 0, 100),
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

        $localByCode = Wilaya::all()->keyBy(fn ($w) => (int) $w->code);
        $updated = 0;
        $skipped = [];

        foreach ($rates as $row) {
            $code = (int) ($row['toTerritoryCode'] ?? 0);
            $wilaya = $localByCode->get($code);
            if (! $wilaya) {
                continue;
            }

            [$home, $stopdesk] = $this->extractRates($row['deliveryPrices'] ?? []);

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
            } else {
                $skipped[] = $wilaya->code.' — '.$wilaya->name_fr;
            }
        }

        return response()->json([
            'ok' => true,
            'message' => "Tarifs de livraison synchronisés pour {$updated} wilaya(s).",
            'updated' => $updated,
            'skipped' => array_slice($skipped, 0, 60),
        ]);
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
            'order' => new OrderResource($order->load(['lines', 'statusHistory', 'callAttempts'])),
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
            } elseif ($home === null) {
                $home = $price; // untyped → treat as home delivery
            }
        }
        return [$home, $stopdesk];
    }

    /** Normalise a place name for fuzzy matching (lowercase, no accents/marks). */
    private function normalise(?string $name): string
    {
        $name = (string) $name;
        // Strip accents where iconv is available.
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($ascii !== false) {
            $name = $ascii;
        }
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name);
        return trim((string) $name);
    }
}
