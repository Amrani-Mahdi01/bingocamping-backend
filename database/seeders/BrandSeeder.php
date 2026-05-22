<?php

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;

class BrandSeeder extends Seeder
{
    public function run(): void
    {
        $brands = [
            ['quechua',     'Quechua',     'FR'],
            ['forclaz',     'Forclaz',     'FR'],
            ['simond',      'Simond',      'FR'],
            ['petzl',       'Petzl',       'FR'],
            ['black-diamond','Black Diamond','US'],
            ['msr',         'MSR',         'US'],
            ['therm-a-rest','Therm-a-Rest','US'],
            ['osprey',      'Osprey',      'US'],
            ['salomon',     'Salomon',     'FR'],
            ['merrell',     'Merrell',     'US'],
            ['gerber',      'Gerber',      'US'],
            ['victorinox',  'Victorinox',  'CH'],
        ];

        foreach ($brands as [$slug, $name, $country]) {
            Brand::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'country' => $country, 'is_active' => true]
            );
        }
    }
}
