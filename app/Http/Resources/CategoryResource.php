<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Output shape for /api/categories and /api/admin/categories.
 *
 * Top-level rows receive a `children` array with their direct subcategories
 * (one level only). Sub-category rows skip it via `whenLoaded`.
 * `image` resolves to an absolute URL when it's a relative /storage/* path.
 */
class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Relative /storage path (as stored) — served same-origin by the
        // storefront's /storage proxy so it loads on mobile networks.
        $image = $this->image;

        return [
            'id' => (string) $this->id,
            'slug' => $this->slug,
            'nameFr' => $this->name_fr,
            'nameAr' => $this->name_ar,
            'descriptionFr' => $this->description_fr,
            'descriptionAr' => $this->description_ar,
            'parentId' => $this->parent_id ? (string) $this->parent_id : null,
            'icon' => $this->icon,
            'image' => $image,
            'displayOrder' => (int) $this->display_order,
            'isActive' => (bool) $this->is_active,
            'productCount' => (int) ($this->products_count ?? 0),
            'children' => $this->whenLoaded('children', function () {
                // Strip the relation on each child before serializing, so
                // grandchildren never leak (we only support 2 levels).
                return $this->children->map(function ($child) {
                    $child->unsetRelation('children');
                    return new CategoryResource($child);
                });
            }, []),
        ];
    }
}
