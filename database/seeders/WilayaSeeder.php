<?php

namespace Database\Seeders;

use App\Models\Wilaya;
use Illuminate\Database\Seeder;

class WilayaSeeder extends Seeder
{
    /**
     * Seeds the 58 Algerian wilayas with bilingual names + ZR Express shipping
     * tarifs/délais. Mirrors lib/mock/wilayas.ts on the frontend.
     */
    public function run(): void
    {
        $data = [
            ['01','Adrar','أدرار','Sud',1100,5],
            ['02','Chlef','الشلف','Centre',600,3],
            ['03','Laghouat','الأغواط','Sud',800,4],
            ['04','Oum El Bouaghi','أم البواقي','Est',700,3],
            ['05','Batna','باتنة','Est',700,3],
            ['06','Béjaïa','بجاية','Est',600,2],
            ['07','Biskra','بسكرة','Sud',800,4],
            ['08','Béchar','بشار','Sud',1000,5],
            ['09','Blida','البليدة','Centre',450,2],
            ['10','Bouira','البويرة','Centre',500,2],
            ['11','Tamanrasset','تمنراست','Sud',1200,5],
            ['12','Tébessa','تبسة','Est',750,3],
            ['13','Tlemcen','تلمسان','Ouest',700,3],
            ['14','Tiaret','تيارت','Centre',650,3],
            ['15','Tizi Ouzou','تيزي وزو','Centre',500,2],
            ['16','Alger','الجزائر','Centre',400,2],
            ['17','Djelfa','الجلفة','Sud',750,3],
            ['18','Jijel','جيجل','Est',650,3],
            ['19','Sétif','سطيف','Est',500,2],
            ['20','Saïda','سعيدة','Ouest',750,3],
            ['21','Skikda','سكيكدة','Est',650,3],
            ['22','Sidi Bel Abbès','سيدي بلعباس','Ouest',700,3],
            ['23','Annaba','عنابة','Est',650,3],
            ['24','Guelma','قالمة','Est',700,3],
            ['25','Constantine','قسنطينة','Est',600,2],
            ['26','Médéa','المدية','Centre',550,2],
            ['27','Mostaganem','مستغانم','Ouest',650,3],
            ['28','MSila','المسيلة','Centre',650,3],
            ['29','Mascara','معسكر','Ouest',700,3],
            ['30','Ouargla','ورقلة','Sud',900,4],
            ['31','Oran','وهران','Ouest',600,2],
            ['32','El Bayadh','البيض','Sud',900,4],
            ['33','Illizi','إيليزي','Sud',1200,5],
            ['34','Bordj Bou Arreridj','برج بوعريريج','Est',600,2],
            ['35','Boumerdès','بومرداس','Centre',450,2],
            ['36','El Tarf','الطارف','Est',750,3],
            ['37','Tindouf','تندوف','Sud',1200,5],
            ['38','Tissemsilt','تيسمسيلت','Centre',700,3],
            ['39','El Oued','الوادي','Sud',900,4],
            ['40','Khenchela','خنشلة','Est',750,3],
            ['41','Souk Ahras','سوق أهراس','Est',750,3],
            ['42','Tipaza','تيبازة','Centre',500,2],
            ['43','Mila','ميلة','Est',650,3],
            ['44','Aïn Defla','عين الدفلى','Centre',600,3],
            ['45','Naâma','النعامة','Sud',1000,4],
            ['46','Aïn Témouchent','عين تموشنت','Ouest',700,3],
            ['47','Ghardaïa','غرداية','Sud',900,4],
            ['48','Relizane','غليزان','Centre',650,3],
            ['49','Timimoun','تيميمون','Sud',1150,5],
            ['50','Bordj Badji Mokhtar','برج باجي مختار','Sud',1200,5],
            ['51','Ouled Djellal','أولاد جلال','Sud',850,4],
            ['52','Béni Abbès','بني عباس','Sud',1100,5],
            ['53','In Salah','عين صالح','Sud',1200,5],
            ['54','In Guezzam','عين قزام','Sud',1200,5],
            ['55','Touggourt','تقرت','Sud',900,4],
            ['56','Djanet','جانت','Sud',1200,5],
            ['57','El MGhair','المغير','Sud',900,4],
            ['58','El Meniaa','المنيعة','Sud',1000,4],
        ];

        foreach ($data as [$code, $nameFr, $nameAr, $region, $shipping, $days]) {
            Wilaya::updateOrCreate(
                ['id' => $code],
                [
                    'code' => $code,
                    'name_fr' => $nameFr,
                    'name_ar' => $nameAr,
                    'region' => $region,
                    'shipping_price' => $shipping,
                    'delivery_days' => $days,
                ]
            );
        }
    }
}
