<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Relative /storage path (as stored) — served same-origin by the
        // storefront's /storage proxy so it loads on mobile networks.
        $logo = $this->logo;

        return [
            'id' => (string) $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'logo' => $logo,
            'country' => $this->country,
            'descriptionFr' => $this->description_fr,
            'descriptionAr' => $this->description_ar,
            'isActive' => (bool) $this->is_active,
            'productCount' => (int) ($this->products_count ?? 0),
        ];
    }
}
