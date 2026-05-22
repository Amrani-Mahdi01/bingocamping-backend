<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBrandRequest;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BrandController extends Controller
{
    /** GET /api/brands — public, active brands only. */
    public function indexPublic(): AnonymousResourceCollection
    {
        $brands = Brand::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
        return BrandResource::collection($brands);
    }

    /** GET /api/admin/brands — admin, all brands. */
    public function indexAdmin(): AnonymousResourceCollection
    {
        $brands = Brand::query()
            ->withCount('products')
            ->orderBy('name')
            ->get();
        return BrandResource::collection($brands);
    }

    /** POST /api/admin/brands */
    public function store(StoreBrandRequest $request): JsonResponse
    {
        $brand = Brand::create($request->toModel());
        return (new BrandResource($brand))->response()->setStatusCode(201);
    }

    /** PUT /api/admin/brands/{brand} */
    public function update(StoreBrandRequest $request, Brand $brand): BrandResource
    {
        $brand->update($request->toModel());
        return new BrandResource($brand->fresh());
    }

    /** DELETE /api/admin/brands/{brand} */
    public function destroy(Brand $brand): JsonResponse
    {
        // FK is restrict — products referencing this brand will block deletion.
        $brand->delete();
        return response()->json(['message' => 'Brand deleted.']);
    }
}
