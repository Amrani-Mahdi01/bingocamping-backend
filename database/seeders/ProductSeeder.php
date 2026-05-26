<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Seeder;

/**
 * Seed a representative catalog: 16 products spread across the
 * 8 top-level categories, each with 2-3 Unsplash photos. Image URLs
 * are absolute so the admin grid renders without a working upload
 * pipeline yet.
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // Resolve category + brand IDs once.
        $cat = Category::pluck('id', 'slug')->all();
        $brand = Brand::pluck('id', 'slug')->all();

        $products = [
            // ───── Tentes & Abris ───────────────────────────────────
            [
                'slug' => 'msr-hubba-nx',
                'sku' => 'MSR-HBX-2P',
                'name_fr' => 'Tente Hubba NX 2 places',
                'name_ar' => 'خيمة Hubba NX لشخصين',
                'description_short_fr' => 'Tente ultralégère 2 places, double paroi imperméable.',
                'description_short_ar' => 'خيمة فائقة الخفة لشخصين، جدار مزدوج مقاوم للماء.',
                'description_fr' => "La référence du bivouac alpin — 1.7 kg, montage en 5 minutes, deux portes, arceaux DAC Featherlite NSL et double paroi 1500 mm. Idéale pour les traversées sans concession sur le poids.",
                'description_ar' => "المرجع في المخيمات الجبلية — 1.7 كغ، نصب في 5 دقائق، بابان، أعمدة DAC Featherlite NSL وجدار مزدوج 1500 مم. مثالية للمسارات الطويلة دون أي تنازل عن الوزن.",
                'category' => 'tentes-2-3-places',
                'brand' => 'msr',
                'price' => 18900,
                'old_price' => null,
                'stock' => 12,
                'is_new' => true,
                'is_best_seller' => true,
                'images' => [
                    'https://images.unsplash.com/photo-1504280390367-361c6d9f38f4?w=1200&q=85&fit=crop',
                    'https://images.unsplash.com/photo-1496080174650-637e3f22fa03?w=1200&q=85&fit=crop',
                    'https://images.unsplash.com/photo-1487730116645-7be521e35813?w=1200&q=85&fit=crop',
                ],
            ],
            [
                'slug' => 'quechua-mh100-3p',
                'sku' => 'QCH-MH100-3P',
                'name_fr' => 'Tente MH100 3 places',
                'name_ar' => 'خيمة MH100 لثلاثة أشخاص',
                'description_short_fr' => 'Tente de randonnée 3 places, montage rapide.',
                'description_short_ar' => 'خيمة مشي لثلاثة أشخاص، نصب سريع.',
                'description_fr' => "Tente dôme accessible, parfaite pour les premières nuits sous tente. Imperméabilité 2000 mm, montage en moins de 10 minutes, deux portes et un auvent ventilé.",
                'description_ar' => "خيمة قبّة في متناول الجميع، مثالية للّيالي الأولى تحت الخيمة. مقاومة للماء 2000 مم، نصب في أقل من 10 دقائق، بابان ومظلّة مهوّاة.",
                'category' => 'tentes-2-3-places',
                'brand' => 'quechua',
                'price' => 7900,
                'old_price' => 9500,
                'stock' => 25,
                'is_promo' => true,
                'images' => [
                    'https://images.unsplash.com/photo-1455496231601-e6195da1f841?w=1200&q=85&fit=crop',
                    'https://images.unsplash.com/photo-1504280390367-361c6d9f38f4?w=1200&q=85&fit=crop',
                ],
            ],

            // ───── Sacs de couchage ─────────────────────────────────
            [
                'slug' => 'forclaz-trek-900',
                'sku' => 'FCZ-T900-M5',
                'name_fr' => 'Sac de couchage Trek 900 -5°C',
                'name_ar' => 'كيس نوم Trek 900 بدرجة ‎-5°م',
                'description_short_fr' => "Sac de couchage trek 3 saisons, garnissage duvet d'oie.",
                'description_short_ar' => 'كيس نوم للترحال ثلاثي المواسم، حشو زغب إوز.',
                'description_fr' => "Garnissage en duvet d'oie 650 cuin, confort jusqu'à -5°C, poids 1.05 kg. Sac compact pour les bivouacs en altitude au printemps comme en automne.",
                'description_ar' => "حشو من زغب الإوز 650 cuin، راحة حتى ‎-5°م، وزن 1.05 كغ. كيس مدمج للمخيمات في الارتفاعات ربيعاً وخريفاً.",
                'category' => 'hiver-grand-froid',
                'brand' => 'forclaz',
                'price' => 13500,
                'old_price' => null,
                'stock' => 18,
                'is_best_seller' => true,
                'images' => [
                    'https://images.unsplash.com/photo-1487730116645-7be521e35813?w=1200&q=85&fit=crop',
                    'https://images.unsplash.com/photo-1455763916899-e8b50eca9967?w=1200&q=85&fit=crop',
                ],
            ],
            [
                'slug' => 'forclaz-mt500-s10',
                'sku' => 'FCZ-MT500-S10',
                'name_fr' => 'Sac de couchage MT500 +10°C',
                'name_ar' => 'كيس نوم MT500 بدرجة ‎+10°م',
                'description_short_fr' => 'Sac d\'été léger pour bivouacs tempérés.',
                'description_short_ar' => 'كيس صيفي خفيف لمخيمات الطقس المعتدل.',
                'description_fr' => "Sac synthétique 3 saisons, confort jusqu'à +10°C, 950 g. Idéal pour les sorties d'été et les nuits courtes en bord de mer.",
                'description_ar' => "كيس اصطناعي ثلاثي المواسم، راحة حتى ‎+10°م، 950 غ. مثالي للخرجات الصيفية والليالي القصيرة قرب البحر.",
                'category' => 'ete-3-saisons',
                'brand' => 'forclaz',
                'price' => 4800,
                'old_price' => null,
                'stock' => 30,
                'images' => [
                    'https://images.unsplash.com/photo-1455763916899-e8b50eca9967?w=1200&q=85&fit=crop',
                ],
            ],
            [
                'slug' => 'thermarest-neoair',
                'sku' => 'TAR-NEO-XL',
                'name_fr' => 'Matelas NeoAir XLite',
                'name_ar' => 'مرتبة NeoAir XLite',
                'description_short_fr' => 'Matelas gonflable ultraléger, R-value 4.2.',
                'description_short_ar' => 'مرتبة قابلة للنفخ فائقة الخفة، R-value 4.2.',
                'description_fr' => "Le matelas de référence pour les randonneurs poids-plume — 340 g, 6.4 cm d'épaisseur, technologie ThermaCapture pour conserver la chaleur corporelle.",
                'description_ar' => "المرتبة المرجعية للمشاة خفيفي الوزن — 340 غ، سمك 6.4 سم، تقنية ThermaCapture للحفاظ على حرارة الجسم.",
                'category' => 'matelas-couchage',
                'brand' => 'therm-a-rest',
                'price' => 19500,
                'old_price' => 22000,
                'stock' => 7,
                'is_new' => true,
                'is_promo' => true,
                'images' => [
                    'https://images.unsplash.com/photo-1502136969935-8d8eef54d77b?w=1200&q=85&fit=crop',
                    'https://images.unsplash.com/photo-1551632811-561732d1e306?w=1200&q=85&fit=crop',
                ],
            ],

            // ───── Cuisine outdoor ──────────────────────────────────
            [
                'slug' => 'msr-pocketrocket-2',
                'sku' => 'MSR-PR2',
                'name_fr' => 'Réchaud PocketRocket 2',
                'name_ar' => 'موقد PocketRocket 2',
                'description_short_fr' => 'Réchaud gaz 73 g, ébullition d\'1 L en 3 min 30.',
                'description_short_ar' => 'موقد غاز 73 غ، يغلي 1 لتر في 3 دقائق ونصف.',
                'description_fr' => "Réchaud à gaz canister ultra-compact — 73 g seulement, pliable dans la paume, allumage rapide. Le plus minimaliste du marché.",
                'description_ar' => "موقد غاز فائق الإحكام — 73 غ فقط، ينطوي في راحة اليد، إشعال سريع. الأكثر بساطة في السوق.",
                'category' => 'rechauds',
                'brand' => 'msr',
                'price' => 6900,
                'old_price' => null,
                'stock' => 22,
                'is_best_seller' => true,
                'images' => [
                    'https://images.unsplash.com/photo-1517824806704-9040b037703b?w=1200&q=85&fit=crop',
                    'https://images.unsplash.com/photo-1517783999520-f068d7431a60?w=1200&q=85&fit=crop',
                ],
            ],
            [
                'slug' => 'forclaz-mh100-popote',
                'sku' => 'FCZ-MH100-POP',
                'name_fr' => 'Popote inox MH100',
                'name_ar' => 'أواني طهي MH100 من الفولاذ',
                'description_short_fr' => 'Kit popote inox 4 pièces pour 1 personne.',
                'description_short_ar' => 'طقم أواني فولاذ من 4 قطع لشخص واحد.',
                'description_fr' => "Kit popote en acier inox : casserole 1L + couvercle + tasse + sac de rangement. Compact, indestructible, compatible feu de bois.",
                'description_ar' => "طقم أواني من الفولاذ المقاوم للصدأ: قدر 1 لتر + غطاء + كوب + كيس تخزين. مدمج، لا يكسر، يتحمّل نار الحطب.",
                'category' => 'popotes-vaisselle',
                'brand' => 'forclaz',
                'price' => 2400,
                'old_price' => null,
                'stock' => 40,
                'images' => [
                    'https://images.unsplash.com/photo-1517824806704-9040b037703b?w=1200&q=85&fit=crop',
                ],
            ],

            // ───── Éclairage ────────────────────────────────────────
            [
                'slug' => 'petzl-actik-core',
                'sku' => 'PTZ-ACTIK-CORE',
                'name_fr' => 'Lampe frontale Actik Core 600 lm',
                'name_ar' => 'كشاف رأس Actik Core بقوة 600 لومن',
                'description_short_fr' => 'Frontale rechargeable USB-C, 600 lumens, IPX4.',
                'description_short_ar' => 'كشاف رأس قابل للشحن USB-C، 600 لومن، IPX4.',
                'description_fr' => "600 lumens, batterie Core rechargeable (compatible 3× AAA en dépannage), bandeau réfléchissant. La frontale tout-terrain alpine/trail.",
                'description_ar' => "600 لومن، بطارية Core قابلة للشحن (متوافقة مع بطاريات AAA عند الحاجة)، شريط رأس عاكس. كشاف الرأس متعدد الاستخدامات للجبال والمسارات.",
                'category' => 'lampes-frontales',
                'brand' => 'petzl',
                'price' => 5400,
                'old_price' => null,
                'stock' => 28,
                'is_best_seller' => true,
                'images' => [
                    'https://images.unsplash.com/photo-1471919743851-c4df8b6ee133?w=1200&q=85&fit=crop',
                    'https://images.unsplash.com/photo-1502136969935-8d8eef54d77b?w=1200&q=85&fit=crop',
                ],
            ],
            [
                'slug' => 'black-diamond-spot-400',
                'sku' => 'BD-SPOT-400',
                'name_fr' => 'Lampe frontale Spot 400',
                'name_ar' => 'كشاف رأس Spot 400',
                'description_short_fr' => 'Frontale 400 lumens, PowerTap, IPX8.',
                'description_short_ar' => 'كشاف رأس 400 لومن، PowerTap، IPX8.',
                'description_fr' => "Gestes PowerTap pour ajuster l'intensité d'un toucher, étanchéité IPX8 jusqu'à 1 m, 400 lumens. La polyvalente alpine.",
                'description_ar' => "إيماءات PowerTap لضبط شدّة الإضاءة بلمسة، عازل IPX8 حتى 1 م، 400 لومن. الكشاف الجبلي متعدد الاستخدامات.",
                'category' => 'lampes-frontales',
                'brand' => 'black-diamond',
                'price' => 4200,
                'old_price' => 4900,
                'stock' => 15,
                'is_promo' => true,
                'images' => [
                    'https://images.unsplash.com/photo-1471919743851-c4df8b6ee133?w=1200&q=85&fit=crop',
                ],
            ],

            // ───── Sacs à dos ───────────────────────────────────────
            [
                'slug' => 'osprey-atmos-50',
                'sku' => 'OSP-ATM-50',
                'name_fr' => 'Sac à dos Atmos AG 50 L',
                'name_ar' => 'حقيبة ظهر Atmos AG 50 لتر',
                'description_short_fr' => 'Sac à dos trek 50 L, suspension Anti-Gravity.',
                'description_short_ar' => 'حقيبة ظهر للترحال 50 لتر، تعليق Anti-Gravity.',
                'description_fr' => "Suspension Anti-Gravity, dos ventilé en mesh, ceinture ajustable au degré près. 50 L pensés pour les treks d'une semaine.",
                'description_ar' => "تعليق Anti-Gravity، ظهر متهوّى من الشبك، حزام قابل للضبط بدقة. 50 لتر مصمّمة لرحلات أسبوع.",
                'category' => 'randonnee-40-65l',
                'brand' => 'osprey',
                'price' => 13900,
                'old_price' => null,
                'stock' => 14,
                'is_best_seller' => true,
                'images' => [
                    'https://images.unsplash.com/photo-1551632811-561732d1e306?w=1200&q=85&fit=crop',
                    'https://images.unsplash.com/photo-1502136969935-8d8eef54d77b?w=1200&q=85&fit=crop',
                ],
            ],
            [
                'slug' => 'osprey-talon-22',
                'sku' => 'OSP-TLN-22',
                'name_fr' => 'Sac à dos Talon 22 L',
                'name_ar' => 'حقيبة ظهر Talon 22 لتر',
                'description_short_fr' => 'Sac journée 22 L, ventilation AirScape.',
                'description_short_ar' => 'حقيبة يومية 22 لتر، تهوية AirScape.',
                'description_fr' => "Sac à dos AirScape ventilé pour les sorties à la journée — 22 L, léger, accessible. Idéal pour les rando d'altitude estivales.",
                'description_ar' => "حقيبة ظهر AirScape مهوّاة للخرجات اليومية — 22 لتر، خفيفة، سهلة الوصول. مثالية لرحلات الارتفاعات الصيفية.",
                'category' => 'journee-20-40l',
                'brand' => 'osprey',
                'price' => 7200,
                'old_price' => null,
                'stock' => 21,
                'is_new' => true,
                'images' => [
                    'https://images.unsplash.com/photo-1551632811-561732d1e306?w=1200&q=85&fit=crop',
                ],
            ],

            // ───── Vêtements ────────────────────────────────────────
            [
                'slug' => 'forclaz-mt900-jacket',
                'sku' => 'FCZ-MT900-JKT',
                'name_fr' => 'Veste imperméable MT900',
                'name_ar' => 'سترة مقاومة للماء MT900',
                'description_short_fr' => 'Hardshell 3 couches, capuche réglable.',
                'description_short_ar' => 'سترة هاردشيل بثلاث طبقات، غطاء رأس قابل للضبط.',
                'description_fr' => "Hardshell 3 couches, membrane 20 000 mm, capuche ajustable au casque, fermeture pit-zip. Conçue pour la pluie alpine soutenue.",
                'description_ar' => "سترة هاردشيل بثلاث طبقات، غشاء 20,000 مم، غطاء رأس قابل للضبط على الخوذة، سحّاب pit-zip. مصمّمة للمطر الجبلي المستمرّ.",
                'category' => 'vestes-impermeables',
                'brand' => 'forclaz',
                'price' => 16900,
                'old_price' => null,
                'stock' => 9,
                'is_new' => true,
                'images' => [
                    'https://images.unsplash.com/photo-1551698618-1dfe5d97d256?w=1200&q=85&fit=crop',
                    'https://images.unsplash.com/photo-1487730116645-7be521e35813?w=1200&q=85&fit=crop',
                ],
            ],

            // ───── Chaussures ───────────────────────────────────────
            [
                'slug' => 'salomon-x-ultra-4',
                'sku' => 'SAL-XUL4-43',
                'name_fr' => 'Chaussures X Ultra 4 GTX',
                'name_ar' => 'أحذية X Ultra 4 GTX',
                'description_short_fr' => 'Chaussures randonnée légères, membrane Gore-Tex.',
                'description_short_ar' => 'أحذية مشي خفيفة، غشاء Gore-Tex.',
                'description_fr' => "Chaussure de randonnée polyvalente — membrane Gore-Tex, semelle Contagrip, châssis Advanced Chassis pour le maintien sur terrain technique.",
                'description_ar' => "حذاء مشي متعدد الاستخدامات — غشاء Gore-Tex، نعل Contagrip، هيكل Advanced Chassis للثبات على التضاريس التقنية.",
                'category' => 'chaussures-randonnee',
                'brand' => 'salomon',
                'price' => 14500,
                'old_price' => null,
                'stock' => 16,
                'is_best_seller' => true,
                'images' => [
                    'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=1200&q=85&fit=crop',
                    'https://images.unsplash.com/photo-1606107557195-0e29a4b5b4aa?w=1200&q=85&fit=crop',
                ],
            ],
            [
                'slug' => 'merrell-moab-3',
                'sku' => 'MRL-MOAB3-42',
                'name_fr' => 'Chaussures Moab 3 mid GTX',
                'name_ar' => 'أحذية Moab 3 mid GTX',
                'description_short_fr' => 'Bottines mid-cut imperméables, semelle Vibram.',
                'description_short_ar' => 'بساطير قصيرة مقاومة للماء، نعل Vibram.',
                'description_fr' => "Bottines mid-cut Gore-Tex, semelle Vibram TC5+, semelle intermédiaire EVA moulée. Confort dès le premier kilomètre.",
                'description_ar' => "بساطير mid-cut مع Gore-Tex، نعل Vibram TC5+، نعل وسط EVA مصبوب. راحة من الكيلومتر الأول.",
                'category' => 'bottes-montagne',
                'brand' => 'merrell',
                'price' => 12900,
                'old_price' => 14500,
                'stock' => 11,
                'is_promo' => true,
                'images' => [
                    'https://images.unsplash.com/photo-1606107557195-0e29a4b5b4aa?w=1200&q=85&fit=crop',
                ],
            ],

            // ───── Accessoires ──────────────────────────────────────
            [
                'slug' => 'victorinox-camper',
                'sku' => 'VIC-CMP-13',
                'name_fr' => 'Couteau suisse Camper',
                'name_ar' => 'سكين سويسرية Camper',
                'description_short_fr' => 'Couteau suisse 13 fonctions, scie à bois.',
                'description_short_ar' => 'سكين سويسرية بـ13 وظيفة، منشار خشب.',
                'description_fr' => "13 fonctions dont scie à bois, ciseaux, ouvre-boîte, tire-bouchon, tournevis. Le compagnon historique des bivouacs.",
                'description_ar' => "13 وظيفة منها منشار خشب، مقصّ، فتاحة علب، فتاحة نبيذ، مفكّ. الرفيق التاريخي للمخيمات.",
                'category' => 'couteaux-multifonctions',
                'brand' => 'victorinox',
                'price' => 3800,
                'old_price' => null,
                'stock' => 35,
                'images' => [
                    'https://images.unsplash.com/photo-1502136969935-8d8eef54d77b?w=1200&q=85&fit=crop',
                ],
            ],
            [
                'slug' => 'gerber-suspension-nxt',
                'sku' => 'GBR-SUS-NXT',
                'name_fr' => 'Multi-outils Suspension NXT',
                'name_ar' => 'أداة متعددة Suspension NXT',
                'description_short_fr' => 'Multi-outils 15 fonctions, gainé inox.',
                'description_short_ar' => 'أداة متعددة بـ15 وظيفة، غمد فولاذ.',
                'description_fr' => "15 outils intégrés (pince à ressort, lames, ciseaux, scie, tournevis…), poignée inox, ouverture une main. L'outil tout-terrain qui ne pèse pas.",
                'description_ar' => "15 أداة مدمجة (ملقط زنبركي، شفرات، مقصّ، منشار، مفكّ…)، مقبض فولاذ، فتح بيد واحدة. الأداة الميدانية الخفيفة.",
                'category' => 'couteaux-multifonctions',
                'brand' => 'gerber',
                'price' => 4900,
                'old_price' => null,
                'stock' => 19,
                'images' => [
                    'https://images.unsplash.com/photo-1502136969935-8d8eef54d77b?w=1200&q=85&fit=crop',
                ],
            ],
        ];

        foreach ($products as $p) {
            $product = Product::updateOrCreate(
                ['slug' => $p['slug']],
                [
                    'sku' => $p['sku'],
                    'name_fr' => $p['name_fr'],
                    'name_ar' => $p['name_ar'],
                    'description_short_fr' => $p['description_short_fr'],
                    'description_short_ar' => $p['description_short_ar'],
                    'description_fr' => $p['description_fr'],
                    'description_ar' => $p['description_ar'],
                    'category_id' => $cat[$p['category']] ?? null,
                    'brand_id' => $brand[$p['brand']] ?? null,
                    'price' => $p['price'],
                    'old_price' => $p['old_price'] ?? null,
                    'stock' => $p['stock'],
                    'low_stock_threshold' => 5,
                    'track_stock' => true,
                    'is_active' => true,
                    'is_new' => $p['is_new'] ?? false,
                    'is_best_seller' => $p['is_best_seller'] ?? false,
                    'is_promo' => $p['is_promo'] ?? false,
                    'published_at' => now(),
                ]
            );

            // Wipe + reseed images so reruns stay consistent.
            $product->images()->delete();
            foreach ($p['images'] as $i => $url) {
                ProductImage::create([
                    'product_id' => $product->id,
                    'url' => $url,
                    'alt_fr' => $p['name_fr'],
                    'alt_ar' => $p['name_ar'],
                    'display_order' => $i + 1,
                    'is_primary' => $i === 0,
                ]);
            }
        }
    }
}
