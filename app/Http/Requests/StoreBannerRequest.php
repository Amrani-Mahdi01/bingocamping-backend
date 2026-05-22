<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST/PUT /api/admin/banners. The route is already
 * protected by `auth:sanctum` + `admin` middleware so `authorize()` just
 * returns true.
 */
class StoreBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'string', 'max:1000'],
            'link' => ['nullable', 'string', 'max:500'],

            'titleFr' => ['nullable', 'string', 'max:255'],
            'titleAr' => ['nullable', 'string', 'max:255'],
            'subtitleFr' => ['nullable', 'string', 'max:1000'],
            'subtitleAr' => ['nullable', 'string', 'max:1000'],
            'ctaLabelFr' => ['nullable', 'string', 'max:100'],
            'ctaLabelAr' => ['nullable', 'string', 'max:100'],

            'displayOrder' => ['nullable', 'integer', 'min:0'],
            'isActive' => ['nullable', 'boolean'],
            'startsAt' => ['nullable', 'date'],
            'endsAt' => ['nullable', 'date', 'after_or_equal:startsAt'],
        ];
    }

    /**
     * Map the camelCase request body to the snake_case DB columns. Empty
     * strings on optional bilingual fields become NULLs so the DB stays
     * tidy (and the fallback chain on the frontend works correctly).
     */
    public function toModel(): array
    {
        $v = $this->validated();
        $nullable = fn ($x) => is_string($x) && trim($x) === '' ? null : $x;

        return [
            'image' => $v['image'] ?? null,
            'link' => $nullable($v['link'] ?? null),
            'title_fr' => $nullable($v['titleFr'] ?? null),
            'title_ar' => $nullable($v['titleAr'] ?? null),
            'subtitle_fr' => $nullable($v['subtitleFr'] ?? null),
            'subtitle_ar' => $nullable($v['subtitleAr'] ?? null),
            'cta_label_fr' => $nullable($v['ctaLabelFr'] ?? null),
            'cta_label_ar' => $nullable($v['ctaLabelAr'] ?? null),
            'display_order' => $v['displayOrder'] ?? 0,
            'is_active' => array_key_exists('isActive', $v) ? (bool) $v['isActive'] : true,
            'starts_at' => $v['startsAt'] ?? null,
            'ends_at' => $v['endsAt'] ?? null,
        ];
    }
}
