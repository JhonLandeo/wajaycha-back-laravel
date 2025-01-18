<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('categories')->insert([
            ['name' => 'Fijos', 'percentage' => 50,  'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Variables', 'percentage' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Ahorro', 'percentage' => 20, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
