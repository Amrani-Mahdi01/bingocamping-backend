<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBrandRequest;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BrandController extends Controller
{
    /** Shown when a create/update collides with the unique brand slug
     *  (derived from the name) — i.e. a brand by that name already exists. */
    private const DUPLICATE_MESSAGE = 'Cette marque existe déjà.';

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
        // The slug is derived from the name (see StoreBrandRequest::toModel),
        // so a duplicate name produces a duplicate slug and trips the unique
        // index. Translate that DB-level constraint into a clean 409 instead
        // of letting it bubble up as a raw 500.
        try {
            $brand = Brand::create($request->toModel());
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => self::DUPLICATE_MESSAGE], 409);
        }
        return (new BrandResource($brand))->response()->setStatusCode(201);
    }

    /** PUT /api/admin/brands/{brand} */
    public function update(StoreBrandRequest $request, Brand $brand): JsonResponse|BrandResource
    {
        try {
            $brand->update($request->toModel());
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => self::DUPLICATE_MESSAGE], 409);
        }
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
