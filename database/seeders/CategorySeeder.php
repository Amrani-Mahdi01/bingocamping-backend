<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * 8 top-level categories with their subcategories.
     * Mirrors lib/mock/categories.ts on the frontend.
     */
    public function run(): void
    {
        $tree = [
            [
                'slug' => 'tentes-abris',
                'name_fr' => 'Tentes & Abris',
                'name_ar' => 'الخيام والمآوي',
                'icon' => 'Tent',
                'subs' => [
                    ['slug' => 'tentes-2-3-places',     'name_fr' => 'Tentes 2-3 places',     'name_ar' => 'خيام 2-3 أشخاص'],
                    ['slug' => 'tentes-4-places-plus',  'name_fr' => 'Tentes 4 places et +',  'name_ar' => 'خيام 4 أشخاص فأكثر'],
                    ['slug' => 'tarps-abris',           'name_fr' => 'Tarps & abris',         'name_ar' => 'مظلات ومآوي'],
                    ['slug' => 'accessoires-tente',     'name_fr' => 'Accessoires de tente',  'name_ar' => 'إكسسوارات الخيام'],
                ],
            ],
            [
                'slug' => 'sacs-de-couchage',
                'name_fr' => 'Sacs de couchage',
                'name_ar' => 'أكياس النوم',
                'icon' => 'Moon',
                'subs' => [
                    ['slug' => 'ete-3-saisons',         'name_fr' => 'Été / 3 saisons',       'name_ar' => 'صيف / 3 فصول'],
                    ['slug' => 'hiver-grand-froid',     'name_fr' => 'Hiver & grand froid',   'name_ar' => 'شتاء وبرد قارس'],
                    ['slug' => 'matelas-couchage',      'name_fr' => 'Matelas de couchage',   'name_ar' => 'فرشات النوم'],
                ],
            ],
            [
                'slug' => 'cuisine-outdoor',
                'name_fr' => 'Cuisine outdoor',
                'name_ar' => 'مطبخ المغامرة',
                'icon' => 'ChefHat',
                'subs' => [
                    ['slug' => 'rechauds',              'name_fr' => 'Réchauds',              'name_ar' => 'المواقد'],
                    ['slug' => 'popotes-vaisselle',     'name_fr' => 'Popotes & vaisselle',   'name_ar' => 'أواني وأدوات الطهي'],
                    ['slug' => 'thermos-gourdes',       'name_fr' => 'Thermos & gourdes',     'name_ar' => 'الترموس والقوارير'],
                    ['slug' => 'lyophilises-rations',   'name_fr' => 'Lyophilisés & rations', 'name_ar' => 'وجبات مجفّفة وحصص'],
                ],
            ],
            [
                'slug' => 'eclairage',
                'name_fr' => 'Éclairage',
                'name_ar' => 'الإضاءة',
                'icon' => 'Lamp',
                'subs' => [
                    ['slug' => 'lampes-frontales',      'name_fr' => 'Lampes frontales',      'name_ar' => 'كشافات الرأس'],
                    ['slug' => 'lanternes',             'name_fr' => 'Lanternes',             'name_ar' => 'الفوانيس'],
                    ['slug' => 'lampes-tactiques',      'name_fr' => 'Lampes tactiques',      'name_ar' => 'كشافات تكتيكية'],
                ],
            ],
            [
                'slug' => 'sacs-a-dos',
                'name_fr' => 'Sacs à dos',
                'name_ar' => 'حقائب الظهر',
                'icon' => 'Backpack',
                'subs' => [
                    ['slug' => 'journee-20-40l',        'name_fr' => 'Journée 20-40L',        'name_ar' => 'يومية 20-40 لتر'],
                    ['slug' => 'randonnee-40-65l',      'name_fr' => 'Randonnée 40-65L',      'name_ar' => 'مشي 40-65 لتر'],
                    ['slug' => 'expedition-65l-plus',   'name_fr' => 'Expédition 65L et +',   'name_ar' => 'رحلات 65 لتر فأكثر'],
                    ['slug' => 'sacs-techniques',       'name_fr' => 'Sacs techniques',       'name_ar' => 'حقائب تقنية'],
                ],
            ],
            [
                'slug' => 'vetements',
                'name_fr' => 'Vêtements',
                'name_ar' => 'الملابس',
                'icon' => 'Shirt',
                'subs' => [
                    ['slug' => 'vestes-impermeables',   'name_fr' => 'Vestes & imperméables', 'name_ar' => 'سترات ومضادات الماء'],
                    ['slug' => 'polaires-pulls',        'name_fr' => 'Polaires & pulls',      'name_ar' => 'بولارات وكنزات'],
                    ['slug' => 'pantalons-shorts',      'name_fr' => 'Pantalons & shorts',    'name_ar' => 'سراويل وقصيرة'],
                    ['slug' => 't-shirts-techniques',   'name_fr' => 'T-shirts techniques',   'name_ar' => 'قمصان تقنية'],
                ],
            ],
            [
                'slug' => 'chaussures',
                'name_fr' => 'Chaussures',
                'name_ar' => 'الأحذية',
                'icon' => 'Footprints',
                'subs' => [
                    ['slug' => 'chaussures-randonnee',  'name_fr' => 'Chaussures de randonnée','name_ar' => 'أحذية المشي'],
                    ['slug' => 'chaussures-trail',      'name_fr' => 'Chaussures de trail',   'name_ar' => 'أحذية الترايل'],
                    ['slug' => 'bottes-montagne',       'name_fr' => 'Bottes de montagne',    'name_ar' => 'بساطير الجبال'],
                ],
            ],
            [
                'slug' => 'accessoires',
                'name_fr' => 'Accessoires',
                'name_ar' => 'الإكسسوارات',
                'icon' => 'Compass',
                'subs' => [
                    ['slug' => 'boussoles-navigation',  'name_fr' => 'Boussoles & navigation','name_ar' => 'بوصلات وملاحة'],
                    ['slug' => 'couteaux-multifonctions','name_fr'=> 'Couteaux multifonctions','name_ar'=> 'سكاكين متعددة الوظائف'],
                    ['slug' => 'premiers-secours',      'name_fr' => 'Premiers secours',      'name_ar' => 'إسعافات أولية'],
                    ['slug' => 'cartes-guides',         'name_fr' => 'Cartes & guides',       'name_ar' => 'خرائط وأدلّة'],
                ],
            ],
        ];

        foreach ($tree as $i => $top) {
            $parent = Category::updateOrCreate(
                ['slug' => $top['slug']],
                [
                    'name_fr' => $top['name_fr'],
                    'name_ar' => $top['name_ar'],
                    'icon' => $top['icon'],
                    'display_order' => $i + 1,
                    'parent_id' => null,
                ]
            );

            foreach ($top['subs'] as $j => $sub) {
                Category::updateOrCreate(
                    ['slug' => $sub['slug']],
                    [
                        'name_fr' => $sub['name_fr'],
                        'name_ar' => $sub['name_ar'],
                        'parent_id' => $parent->id,
                        'icon' => $top['icon'],
                        'display_order' => $j + 1,
                    ]
                );
            }
        }
    }
}
