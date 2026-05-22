<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBannerRequest;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BannerController extends Controller
{
    /**
     * GET /api/banners — public listing for the storefront hero slider.
     */
    public function indexPublic(): AnonymousResourceCollection
    {
        $banners = Banner::query()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        return BannerResource::collection($banners);
    }

    /**
     * GET /api/admin/banners — full list including inactive, for the dashboard.
     */
    public function indexAdmin(): AnonymousResourceCollection
    {
        $banners = Banner::query()
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        return BannerResource::collection($banners);
    }

    /** POST /api/admin/banners */
    public function store(StoreBannerRequest $request): JsonResponse
    {
        $data = $request->toModel();

        // Default display_order to the bottom of the list when not given.
        if (! array_key_exists('display_order', $data) || $data['display_order'] === null) {
            $data['display_order'] = (int) (Banner::max('display_order') ?? 0) + 1;
        }

        $banner = Banner::create($data);

        return (new BannerResource($banner))
            ->response()
            ->setStatusCode(201);
    }

    /** PUT /api/admin/banners/{banner} */
    public function update(StoreBannerRequest $request, Banner $banner): BannerResource
    {
        $banner->update($request->toModel());
        return new BannerResource($banner->fresh());
    }

    /** DELETE /api/admin/banners/{banner} */
    public function destroy(Banner $banner): JsonResponse
    {
        $banner->delete();
        return response()->json(['message' => 'Banner deleted.']);
    }

    /**
     * POST /api/admin/banners/{banner}/move
     * Body: { direction: "up" | "down" }
     * Swaps display_order with the adjacent banner in that direction.
     */
    public function move(Request $request, Banner $banner): JsonResponse
    {
        $direction = $request->validate([
            'direction' => ['required', 'in:up,down'],
        ])['direction'];

        $q = Banner::query()->where('id', '!=', $banner->id);
        $neighbor = $direction === 'up'
            ? $q->where('display_order', '<', $banner->display_order)
                ->orderByDesc('display_order')
                ->first()
            : $q->where('display_order', '>', $banner->display_order)
                ->orderBy('display_order')
                ->first();

        if (! $neighbor) {
            return response()->json(['message' => 'Already at the edge.']);
        }

        $a = $banner->display_order;
        $b = $neighbor->display_order;
        $banner->update(['display_order' => $b]);
        $neighbor->update(['display_order' => $a]);

        return response()->json(['message' => 'Order updated.']);
    }
}
