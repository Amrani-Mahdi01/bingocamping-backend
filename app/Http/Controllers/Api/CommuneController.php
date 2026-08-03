<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commune;
use App\Models\Wilaya;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommuneController extends Controller
{
    /** GET /api/admin/wilayas/{wilaya}/communes */
    public function indexAdmin(Wilaya $wilaya): JsonResponse
    {
        $rows = $wilaya->communes()->orderBy('name_fr')->get();
        return response()->json(['data' => $rows->map(fn ($c) => $this->serialize($c))]);
    }

    /** GET /api/wilayas/{wilaya}/communes — public read-only. */
    public function indexPublic(Wilaya $wilaya): JsonResponse
    {
        $rows = $wilaya->communes()->orderBy('name_fr')->get();
        return response()->json(['data' => $rows->map(fn ($c) => $this->serialize($c))]);
    }

    /** POST /api/admin/wilayas/{wilaya}/communes */
    public function store(Request $request, Wilaya $wilaya): JsonResponse
    {
        $payload = $this->normalisePayload($request->all());
        $payload['wilaya_id'] = $wilaya->id;

        $validated = validator($payload, [
            'code'    => 'required|string|max:16',
            'name_fr' => 'required|string|max:120',
            'name_ar' => 'required|string|max:120',
        ])->validate();

        // Ensure (wilaya_id, code) is unique — explicit because the unique
        // is a composite index, not a single-column rule.
        $exists = Commune::query()
            ->where('wilaya_id', $wilaya->id)
            ->where('code', $validated['code'])
            ->exists();
        if ($exists) {
            return response()->json([
                'message' => 'Une commune avec ce code existe déjà pour cette wilaya.',
                'errors' => [
                    'code' => ['Code déjà utilisé dans cette wilaya.'],
                ],
            ], 422);
        }

        $commune = Commune::create([
            'wilaya_id' => $wilaya->id,
            'code'      => $validated['code'],
            'name_fr'   => $validated['name_fr'],
            'name_ar'   => $validated['name_ar'],
        ]);

        return response()->json(['data' => $this->serialize($commune)], 201);
    }

    /** DELETE /api/admin/wilayas/{wilaya}/communes/{commune} */
    public function destroy(Wilaya $wilaya, Commune $commune): JsonResponse
    {
        if ($commune->wilaya_id !== $wilaya->id) {
            return response()->json(['message' => 'Commune not found.'], 404);
        }
        $commune->delete();
        return response()->json(['message' => 'Commune deleted.']);
    }

    private function normalisePayload(array $payload): array
    {
        $aliases = [
            'nameFr' => 'name_fr',
            'nameAr' => 'name_ar',
        ];
        foreach ($aliases as $camel => $snake) {
            if (isset($payload[$camel]) && ! isset($payload[$snake])) {
                $payload[$snake] = $payload[$camel];
            }
        }
        return $payload;
    }

    private function serialize(Commune $c): array
    {
        return [
            'id' => (int) $c->id,
            'wilayaId' => $c->wilaya_id,
            'code' => $c->code,
            'name' => $c->name_fr,
            'nameAr' => $c->name_ar,
            'hasStopDesk' => (bool) $c->has_stop_desk,
        ];
    }
}
