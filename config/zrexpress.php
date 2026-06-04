<?php

/*
|--------------------------------------------------------------------------
| ZR Express delivery integration
|--------------------------------------------------------------------------
|
| Non-secret defaults for the ZR Express API (https://docs.zrexpress.app).
| The actual credentials (API key / tenant) are NOT stored here — they're
| managed at runtime by the admin through /api/admin/zr/settings and live in
| the `site_settings` table as non-public keys, so they never reach the
| storefront and can be rotated without a redeploy. The keys below name the
| settings rows the integration reads/writes.
|
| The endpoint paths in ZR's docs are inconsistent about the version segment
| (some show `v1`, some `v1.0`). `version` is overridable via env + the admin
| panel so it can be corrected without a code change once the live account is
| connected.
|
*/

return [

    // Base origin for the ZR Express API. Overridable so a future migration
    // to another host (or a sandbox) is a config change, not a code change.
    'base_url' => env('ZR_BASE_URL', 'https://api.zrexpress.app'),

    // API version path segment, e.g. https://api.zrexpress.app/api/{version}/...
    'version' => env('ZR_API_VERSION', 'v1.0'),

    // HTTP timeout (seconds) for every ZR call.
    'timeout' => (int) env('ZR_TIMEOUT', 20),

    // Default delivery type sent when creating a parcel. The storefront only
    // does home delivery today; "home" matches ZR's deliveryType enum
    // ('home' | 'pickup-point' | 'return').
    'default_delivery_type' => env('ZR_DEFAULT_DELIVERY_TYPE', 'home'),

    /*
    | site_settings keys. All stored with is_public = false.
    |  - api_key      : the "Secret API Key (token)" from the ZR portal.
    |  - tenant       : X-Tenant header value. Optional — only sent when set;
    |                   newer ZR keys are tenant-scoped and don't need it.
    |  - enabled      : master on/off switch for the whole integration.
    |  - auto_send    : push to ZR automatically when an order is confirmed.
    */
    'keys' => [
        'api_key'   => 'zr.api_key',
        'tenant'    => 'zr.tenant',
        'enabled'   => 'zr.enabled',
        'auto_send' => 'zr.auto_send',
        'base_url'  => 'zr.base_url',
        'version'   => 'zr.version',
    ],

    /*
    | Maps a ZR parcel state slug (newState in the state-history feed) onto
    | one of our Order::STATUSES. States not listed here leave the local
    | order status untouched (we still record the raw zr_state). Forward-only
    | by construction: confirmed → preparing → shipped → delivered, with
    | return/cancel mapped to the matching terminal state.
    */
    'status_map' => [
        // Received / confirmation phase — order is already 'confirmed' locally
        // once we push it, so these keep it there.
        'commande_recue'       => 'confirmed',
        'en_traitement'        => 'confirmed',
        'appel_confirmation'   => 'confirmed',
        'commande_confirmee'   => 'confirmed',

        // Packing
        'en_preparation'       => 'preparing',
        'pret_a_expedier'      => 'preparing',

        // In the delivery network
        'confirme_au_bureau'   => 'shipped',
        'dispatch'             => 'shipped',
        'vers_wilaya'          => 'shipped',
        'en_livraison'         => 'shipped',
        'sortie_en_livraison'  => 'shipped',

        // Delivered / cash collected
        'livre'                => 'delivered',
        'encaisse'             => 'delivered',
        'recouvert'            => 'delivered',

        // Returns / cancellations — slugs vary across ZR accounts, so cover
        // the common variants. Unknown slugs simply leave the status as-is.
        'retour'               => 'returned',
        'retourne'             => 'returned',
        'retour_au_vendeur'    => 'returned',
        'retour_vendeur'       => 'returned',
        'echec_livraison'      => 'returned',
        'annule'               => 'cancelled',
        'annulee'              => 'cancelled',
    ],
];
