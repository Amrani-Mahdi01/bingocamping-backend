<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        Admin::updateOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Admin BINGO',
                'password' => '123456', // hashed by the model cast
                'role' => 'owner',
                'is_active' => true,
            ]
        );

        // Keep the older bingo.dz address active as a fallback admin.
        Admin::updateOrCreate(
            ['email' => 'admin@bingo.dz'],
            [
                'name' => 'Admin BINGO',
                'password' => '123456',
                'role' => 'owner',
                'is_active' => true,
            ]
        );
    }
}
