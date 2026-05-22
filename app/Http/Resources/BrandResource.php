<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $logo = $this->logo;
        if (is_string($logo) && str_starts_with($logo, '/storage/')) {
            $logo = rtrim((string) config('app.url'), '/').$logo;
        }

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
