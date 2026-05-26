<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wilaya;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WilayaController extends Controller
{
    /** GET /api/wilayas — public, used by the storefront delivery page. */
    public function indexPublic(): JsonResponse
    {
        $rows = Wilaya::query()->orderBy('code')->get();
        return response()->json(['data' => $rows->map(fn ($w) => $this->serialize($w))]);
    }

    /** GET /api/admin/wilayas — admin, same shape as public for now. */
    public function indexAdmin(): JsonResponse
    {
        $rows = Wilaya::query()->orderBy('code')->get();
        return response()->json(['data' => $rows->map(fn ($w) => $this->serialize($w))]);
    }

    /**
     * POST /api/admin/wilayas — create a new wilaya row. Used when the
     * admin wants to add a region the seeder didn't cover (e.g. one of
     * the post-2019 newly-promoted southern wilayas, or a custom test row).
     * Accepts camelCase or snake_case in the body.
     */
    public function store(Request $request): JsonResponse
    {
        $payload = $this->normalisePayload($request->all());

        // Pad the code now so the unique check works against the stored form.
        if (isset($payload['code']) && is_string($payload['code'])) {
            $payload['code'] = str_pad($payload['code'], 2, '0', STR_PAD_LEFT);
        }
        $validated = validator($payload, [
            'code'            => 'required|string|regex:/^[0-9]{2}$/|unique:wilayas,code',
            'name_fr'         => 'required|string|max:120',
            'name_ar'         => 'required|string|max:120',
            'region'          => 'required|in:Nord,Centre,Est,Ouest,Sud',
            'shipping_price'  => 'required|integer|min:0|max:100000',
            'stop_desk_price' => 'required|integer|min:0|max:100000',
            'delivery_days'   => 'required|integer|min:1|max:30',
        ])->validate();

        // id mirrors the code (string PK).
        $validated['id'] = $validated['code'];

        $wilaya = Wilaya::create($validated);

        return response()->json(['data' => $this->serialize($wilaya)], 201);
    }

    /**
     * PUT /api/admin/wilayas/{wilaya} — update shipping price and delivery
     * days. The other columns (code, names, region) are seeded reference
     * data and not exposed for editing through the admin UI.
     *
     * Frontend sends camelCase (`shippingPrice`, `deliveryDays`); accept
     * both casings so the API stays forgiving.
     */
    public function update(Request $request, Wilaya $wilaya): JsonResponse
    {
        $payload = $this->normalisePayload($request->all());
        $validated = validator($payload, [
            'shipping_price'  => 'required|integer|min:0|max:100000',
            'stop_desk_price' => 'required|integer|min:0|max:100000',
            'delivery_days'   => 'required|integer|min:1|max:30',
        ])->validate();

        $wilaya->update($validated);

        return response()->json(['data' => $this->serialize($wilaya->fresh())]);
    }

    /** Map any incoming camelCase keys to the snake_case column names. */
    private function normalisePayload(array $payload): array
    {
        $aliases = [
            'shippingPrice' => 'shipping_price',
            'stopDeskPrice' => 'stop_desk_price',
            'deliveryDays'  => 'delivery_days',
            'nameFr'        => 'name_fr',
            'nameAr'        => 'name_ar',
        ];
        foreach ($aliases as $camel => $snake) {
            if (isset($payload[$camel]) && ! isset($payload[$snake])) {
                $payload[$snake] = $payload[$camel];
            }
        }
        return $payload;
    }

    /** Snake_case → camelCase to match the frontend `Wilaya` type. */
    private function serialize(Wilaya $w): array
    {
        return [
            'id' => $w->id,
            'code' => $w->code,
            'name' => $w->name_fr,
            'nameAr' => $w->name_ar,
            'region' => $w->region,
            'shippingPrice' => $w->shipping_price,
            'stopDeskPrice' => $w->stop_desk_price,
            'deliveryDays' => $w->delivery_days,
        ];
    }
}
