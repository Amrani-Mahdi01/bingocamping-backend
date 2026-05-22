<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'title_fr',
    'title_ar',
    'subtitle_fr',
    'subtitle_ar',
    'cta_label_fr',
    'cta_label_ar',
    'image',
    'link',
    'display_order',
    'is_active',
    'starts_at',
    'ends_at',
])]
class Banner extends Model
{
    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Resolve the stored image path to an absolute URL the frontend can
     * load directly. Absolute URLs in the column pass through unchanged
     * (useful for placeholder seeds that already point elsewhere).
     */
    public function getAbsoluteImageUrlAttribute(): string
    {
        $raw = (string) $this->image;
        if ($raw === '' || preg_match('#^https?://#i', $raw)) {
            return $raw;
        }
        $base = rtrim((string) config('app.url'), '/');
        $path = str_starts_with($raw, '/') ? $raw : '/'.$raw;
        return $base.$path;
    }
}
