<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Output shape for /api/banners and /api/admin/banners.
 * Matches the front-end Banner type — bilingual fields surface as
 * `titleFr` / `titleAr` etc.
 */
class BannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'image' => $this->absolute_image_url,
            'link' => $this->link,

            'titleFr' => $this->title_fr,
            'titleAr' => $this->title_ar,
            'subtitleFr' => $this->subtitle_fr,
            'subtitleAr' => $this->subtitle_ar,
            'ctaLabelFr' => $this->cta_label_fr,
            'ctaLabelAr' => $this->cta_label_ar,

            'displayOrder' => (int) $this->display_order,
            'isActive' => (bool) $this->is_active,
            'startsAt' => $this->starts_at?->toIso8601String(),
            'endsAt' => $this->ends_at?->toIso8601String(),
        ];
    }
}
