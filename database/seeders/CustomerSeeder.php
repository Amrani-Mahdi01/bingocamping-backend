<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        Customer::updateOrCreate(
            ['email' => 'yacine@example.dz'],
            [
                'first_name' => 'Yacine',
                'last_name' => 'Benali',
                'phone' => '+213 6XX XXX XXX',
                'password' => 'password',
                'wilaya_id' => '19',
            ]
        );
    }
}
