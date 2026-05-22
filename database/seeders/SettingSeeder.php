<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Seeds the keys the storefront expects to find, with safe defaults so
     * a fresh install still renders something while the admin hasn't filled
     * anything in yet.
     */
    public function run(): void
    {
        $defaults = [
            // Logo path is null by default → storefront falls back to text mark.
            ['site.logo',           null,    true],
            ['site.logo_alt_fr',    'BINGO', true],
            ['site.logo_alt_ar',    'بينغو', true],
            // Display tuning — pixels for all three.
            ['site.logo_height',    '36',    true],
            ['site.logo_max_width', '180',   true],
            ['site.logo_radius',    '0',     true],

            // Contact channels — surfaced as tel:/mailto:/wa.me links in
            // the footer, contact page, and account order Support card.
            ['contact.phone',         '+213 36 XX XX XX',                  true],
            ['contact.email',         'contact@bingo.dz',                  true],
            ['contact.whatsapp',      '+213 6 XX XX XX XX',                true],
            ['contact.address.fr',    'Cité Hassan Bey, Sétif 19000, Algérie', true],
            ['contact.address.ar',    'حي حسن باي، سطيف 19000، الجزائر',     true],
            // Opening hours per day. Saturday → Friday in the FR-DZ week.
            ['contact.hours.sam',     '9h-18h',  true],
            ['contact.hours.dim',     '9h-18h',  true],
            ['contact.hours.lun',     '9h-18h',  true],
            ['contact.hours.mar',     '9h-18h',  true],
            ['contact.hours.mer',     '9h-18h',  true],
            ['contact.hours.jeu',     '9h-18h',  true],
            ['contact.hours.ven',     '14h-18h', true],

            // Social URLs — null/empty means "don't render this icon".
            ['social.facebook',          'https://facebook.com/bingo.dz',  true],
            ['social.instagram',         'https://instagram.com/bingo.dz', true],
            ['social.tiktok',            null,                              true],
            ['social.youtube',           null,                              true],
            ['social.whatsapp_business', '+213 6 XX XX XX XX',              true],
        ];

        foreach ($defaults as [$key, $value, $public]) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'is_public' => $public],
            );
        }
    }
}
