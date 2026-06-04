<?php

namespace App\Services\ZrExpress;

use App\Models\Commune;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Wilaya;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Thin client over the ZR Express REST API (https://docs.zrexpress.app).
 *
 * Credentials (API key / tenant) are read live from the `site_settings`
 * table so the admin can paste/rotate the token from the dashboard without
 * a redeploy — there are no ZR secrets in .env or config. Every method
 * throws {@see ZrExpressException} on a missing token or a non-2xx reply, so
 * callers can surface a single, French, user-facing message.
 *
 * COD amount limit per parcel (ZR-side): 150 000 DZD.
 */
class ZrExpressService
{
    private const MAX_AMOUNT_DZD = 150_000;

    // ---- Configuration state (read from site_settings) ---------------------

    public function isConfigured(): bool
    {
        return filled($this->setting('api_key'));
    }

    /** Master switch AND credentials present. */
    public function enabled(): bool
    {
        return $this->setting('enabled') === '1' && $this->isConfigured();
    }

    /** Whether confirming an order should auto-push it to ZR. */
    public function autoSendEnabled(): bool
    {
        return $this->setting('auto_send') === '1';
    }

    private function apiKey(): ?string
    {
        return $this->setting('api_key');
    }

    private function tenant(): ?string
    {
        return $this->setting('tenant');
    }

    private function baseUrl(): string
    {
        $stored = $this->setting('base_url');
        return rtrim($stored ?: (string) config('zrexpress.base_url'), '/');
    }

    private function version(): string
    {
        return $this->setting('version') ?: (string) config('zrexpress.version');
    }

    /** Read a ZR setting by its short name (see config('zrexpress.keys')). */
    private function setting(string $name): ?string
    {
        $key = config("zrexpress.keys.$name");
        return $key ? Setting::get($key) : null;
    }

    // ---- HTTP plumbing ------------------------------------------------------

    private function http(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new ZrExpressException(
                "ZR Express n'est pas configuré : ajoutez le token API dans les réglages."
            );
        }

        $headers = [
            'X-Api-Key' => $this->apiKey(),
            // The labels endpoint documents Bearer auth while the rest use
            // X-Api-Key; sending both lets either gate pass with one token.
            'Authorization' => 'Bearer '.$this->apiKey(),
        ];
        // Optional — newer per-tenant keys don't require it, older ones do.
        if (filled($this->tenant())) {
            $headers['X-Tenant'] = $this->tenant();
        }

        return Http::baseUrl($this->baseUrl().'/api/'.$this->version().'/')
            ->timeout((int) config('zrexpress.timeout', 20))
            ->acceptJson()
            ->asJson()
            ->withHeaders($headers);
    }

    /** Issue a request and decode JSON, raising ZrExpressException on failure. */
    private function call(string $method, string $path, array $payload = []): Response
    {
        $path = ltrim($path, '/');

        try {
            $request = $this->http();
            $response = strtoupper($method) === 'GET'
                ? $request->get($path, $payload)
                : $request->send(strtoupper($method), $path, ['json' => $payload]);
        } catch (ZrExpressException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ZrExpressException(
                'Connexion à ZR Express impossible : '.$e->getMessage(),
                [],
                0,
                $e,
            );
        }

        if ($response->failed()) {
            $body = $response->json() ?? $response->body();
            Log::warning('ZR Express API error', [
                'method' => $method,
                'path' => $path,
                'status' => $response->status(),
                'body' => $body,
            ]);
            throw new ZrExpressException(
                "ZR Express a renvoyé une erreur (HTTP {$response->status()}).",
                ['status' => $response->status(), 'body' => $body],
                $response->status(),
            );
        }

        return $response;
    }

    // ---- Endpoints ----------------------------------------------------------

    /** GET /users/profile — used by the admin "Tester la connexion" button. */
    public function testConnection(): array
    {
        $data = $this->call('GET', 'users/profile')->json();
        return is_array($data) ? $data : [];
    }

    /**
     * POST /territories/search — paginated territory list. $filter is ZR's
     * advancedFilter object (e.g. {field:'level',operator:'eq',value:'wilaya'}).
     * Returns the flat array of territory rows (unwraps the paged envelope).
     */
    public function searchTerritories(?array $filter = null, int $pageSize = 5000): array
    {
        $body = [
            'pageNumber' => 1,
            'pageSize' => $pageSize,
            'orderBy' => ['code asc'],
        ];
        if ($filter) {
            $body['advancedFilter'] = $filter;
        }
        return $this->unwrap($this->call('POST', 'territories/search', $body)->json());
    }

    /** GET /delivery-pricing/rates — effective per-territory delivery prices. */
    public function getRates(): array
    {
        return $this->unwrap($this->call('GET', 'delivery-pricing/rates')->json());
    }

    /** POST /parcels — create a parcel. Returns the decoded body ({ id: uuid }). */
    public function createParcel(Order $order): array
    {
        $payload = $this->buildParcelPayload($order);
        $data = $this->call('POST', 'parcels', $payload)->json();
        return is_array($data) ? $data : [];
    }

    /** GET /parcels/{id} — full parcel record (trackingNumber, state, …). */
    public function getParcel(string $parcelId): array
    {
        $data = $this->call('GET', "parcels/{$parcelId}")->json();
        return is_array($data) ? $data : [];
    }

    /** GET /parcels/{id}/state-history — ordered list of state transitions. */
    public function getParcelStateHistory(string $parcelId): array
    {
        return $this->unwrap($this->call('GET', "parcels/{$parcelId}/state-history")->json());
    }

    /**
     * POST /parcels/labels/individual — generate printable labels (bordereaux).
     * Accepts 1..50 tracking numbers; returns { parcelLabelFiles:[{trackingNumber,
     * fileUrl}], failedTrackingNumbers:[] } where fileUrl is a ready-to-open HTML.
     */
    public function generateLabels(array $trackingNumbers): array
    {
        $trackingNumbers = array_values(array_filter(array_map('strval', $trackingNumbers)));
        if ($trackingNumbers === []) {
            throw new ZrExpressException('Aucun numéro de suivi à imprimer.');
        }
        if (count($trackingNumbers) > 50) {
            throw new ZrExpressException('ZR Express limite l’impression à 50 colis à la fois.');
        }
        $data = $this->call('POST', 'parcels/labels/individual', [
            'trackingNumbers' => $trackingNumbers,
        ])->json();
        return is_array($data) ? $data : [];
    }

    // ---- Mapping helpers ----------------------------------------------------

    /**
     * Translate a ZR `state` value (string slug or {slug,name,...} object)
     * into one of Order::STATUSES, or null when there's no mapping.
     */
    public function mapState(mixed $state): ?string
    {
        $slug = $this->extractStateSlug($state);
        if ($slug === null) {
            return null;
        }
        return config('zrexpress.status_map')[$slug] ?? null;
    }

    /** Pull the raw slug out of ZR's `state` field, whatever its shape. */
    public function extractStateSlug(mixed $state): ?string
    {
        if (is_string($state)) {
            return $state !== '' ? $state : null;
        }
        if (is_array($state)) {
            $slug = $state['slug'] ?? $state['code'] ?? $state['key'] ?? $state['name'] ?? null;
            return is_string($slug) && $slug !== '' ? $slug : null;
        }
        return null;
    }

    /**
     * Build the POST /parcels request body from a local order. Requires the
     * order's wilaya + commune to have been mapped to ZR territory UUIDs via
     * the "sync territories" admin action — throws a clear error otherwise.
     */
    public function buildParcelPayload(Order $order): array
    {
        $wilaya = Wilaya::find($order->wilaya_id);
        if (! $wilaya || ! $wilaya->zr_territory_id) {
            throw new ZrExpressException(
                "La wilaya « {$order->wilaya_name} » n'est pas liée à ZR Express. "
                ."Lancez « Synchroniser les territoires » dans les réglages ZR."
            );
        }

        $commune = Commune::where('wilaya_id', $order->wilaya_id)
            ->where(function ($q) use ($order) {
                $q->where('name_fr', $order->commune)
                  ->orWhere('name_ar', $order->commune);
            })
            ->first();
        if (! $commune || ! $commune->zr_district_id) {
            throw new ZrExpressException(
                "La commune « {$order->commune} » n'est pas liée à ZR Express. "
                ."Lancez « Synchroniser les territoires » dans les réglages ZR."
            );
        }

        $amount = (int) $order->total;
        if ($amount > self::MAX_AMOUNT_DZD) {
            throw new ZrExpressException(
                "Le montant de la commande (".number_format($amount, 0, ',', ' ')." DA) "
                ."dépasse la limite ZR Express de ".number_format(self::MAX_AMOUNT_DZD, 0, ',', ' ')." DA."
            );
        }

        $order->loadMissing('lines');

        $products = $order->lines->map(fn ($line) => [
            'productName' => mb_substr((string) $line->product_name, 0, 250),
            'productSku' => $line->sku,
            'unitPrice' => (float) $line->unit_price,
            'quantity' => (int) $line->quantity,
            // 'none' = the product isn't tracked in ZR's own warehouse, so
            // productId/SKU registration isn't required on their side.
            'stockType' => 'none',
        ])->values()->all();

        $name = trim($order->customer_first_name.' '.$order->customer_last_name);

        return [
            'customer' => [
                // Random UUID — we don't maintain customer records inside ZR.
                'customerId' => (string) Str::uuid(),
                'name' => mb_substr($name !== '' ? $name : 'Client', 0, 100),
                'phone' => [
                    'number1' => (string) $order->customer_phone,
                ],
            ],
            'deliveryAddress' => [
                'cityTerritoryId' => $wilaya->zr_territory_id,
                'districtTerritoryId' => $commune->zr_district_id,
                'street' => $order->address ? mb_substr((string) $order->address, 0, 250) : null,
            ],
            'orderedProducts' => $products,
            'deliveryType' => (string) config('zrexpress.default_delivery_type', 'home'),
            'description' => $this->buildDescription($order),
            'amount' => (float) $amount,
            // Our order number — lets us reconcile ZR parcels back to orders
            // and gives ZR an idempotency handle on retries.
            'externalId' => mb_substr((string) $order->order_number, 0, 100),
        ];
    }

    /** A 2..250 char human description of the parcel contents (ZR-required). */
    private function buildDescription(Order $order): string
    {
        $parts = $order->lines->map(
            fn ($l) => trim((int) $l->quantity.'x '.$l->product_name)
        )->filter()->all();

        $desc = trim(implode(', ', $parts));
        if ($desc === '') {
            $desc = 'Commande '.$order->order_number;
        }
        // ZR requires 2..250 chars.
        $desc = mb_substr($desc, 0, 250);
        return mb_strlen($desc) >= 2 ? $desc : str_pad($desc, 2, '.');
    }

    /**
     * Unwrap a ZR paged/enveloped response down to its row array. Handles the
     * { data:[…] } / { items:[…] } envelopes as well as a bare top-level array.
     */
    private function unwrap(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }
        if (array_is_list($json)) {
            return $json;
        }
        foreach (['data', 'items', 'results', 'value'] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                return $json[$key];
            }
        }
        return [];
    }
}
