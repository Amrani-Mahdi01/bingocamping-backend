<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Order matters — children reference parents.
        $this->call([
            WilayaSeeder::class,
            CommuneSeeder::class,
            BrandSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
            BannerSeeder::class,
            AdminSeeder::class,
            CustomerSeeder::class,
            SettingSeeder::class,
        ]);
    }
}
