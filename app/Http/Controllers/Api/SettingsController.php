<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    /** Settings whose key the admin is allowed to write through the API. */
    private const WRITABLE_KEYS = [
        'site.logo',
        'site.logo_alt_fr',
        'site.logo_alt_ar',
        // Display tuning — all stored as strings of integers.
        'site.logo_height',     // px, 20..96
        'site.logo_max_width',  // px, 60..320
        'site.logo_radius',     // px, 0..48 (use 9999 for a full circle)
    ];

    /**
     * Key namespaces the admin can freely write to. Anything under one of
     * these dotted prefixes is accepted, in addition to the explicit
     * keys above. Used for namespaces with many or dynamic sub-keys:
     *  - contact.*  → phone, email, whatsapp, address.{fr,ar}, hours.{day}, hours.{day}.off
     *  - social.*   → facebook, instagram, tiktok, youtube, whatsapp_business
     *  - page.*     → admin-edited overrides for static page strings
     */
    private const WRITABLE_PREFIXES = [
        'contact.',
        'social.',
        'page.',
        // Homepage banner / featured-product picks edited from /admin/banners.
        // Examples: home.featured.slug.0..5, home.promo.title.fr,
        // home.promo.title.ar, home.promo.cta.fr, home.promo.cta.ar,
        // home.promo.link.
        'home.',
    ];

    private function isWritable(string $key): bool
    {
        if (in_array($key, self::WRITABLE_KEYS, true)) {
            return true;
        }
        foreach (self::WRITABLE_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * GET /api/settings — every public setting as a flat object so the
     * storefront can call it once and grab everything it needs.
     *
     * Response shape: { "site.logo": "/storage/…", "site.logo_alt_fr": "BINGO", … }
     * Image paths (those starting with "/storage/") are resolved to absolute
     * URLs so the frontend can plug them directly into <Image>.
     */
    public function indexPublic(): JsonResponse
    {
        return response()->json(['data' => $this->serialize(true)]);
    }

    /** GET /api/admin/settings — every setting, public or not. */
    public function indexAdmin(): JsonResponse
    {
        return response()->json(['data' => $this->serialize(false)]);
    }

    /**
     * PUT /api/admin/settings — bulk-update writable keys.
     * Body: { "site.logo": "/storage/…", "site.logo_alt_fr": "…", … }
     */
    public function update(Request $request): JsonResponse
    {
        $payload = $request->all();
        $base = rtrim((string) config('app.url'), '/');

        foreach ($payload as $key => $value) {
            if (! $this->isWritable($key)) {
                continue;
            }
            $value = is_string($value) ? trim($value) : $value;
            if ($value === '') {
                $value = null;
            }
            // If the client sends back the absolute URL we returned, strip
            // the host so the stored value stays portable across envs.
            if (is_string($value) && str_starts_with($value, $base.'/storage/')) {
                $value = substr($value, strlen($base));
            }
            Setting::set($key, $value, isPublic: true);
        }

        return $this->indexAdmin();
    }

    private function serialize(bool $publicOnly): array
    {
        $query = Setting::query();
        if ($publicOnly) {
            $query->where('is_public', true);
        }
        $rows = $query->get();
        $base = rtrim((string) config('app.url'), '/');

        $out = [];
        foreach ($rows as $row) {
            $val = $row->value;
            if (is_string($val) && str_starts_with($val, '/storage/')) {
                $val = $base.$val;
            }
            $out[$row->key] = $val;
        }
        return $out;
    }
}
