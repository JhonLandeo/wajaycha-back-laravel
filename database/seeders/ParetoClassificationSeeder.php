<?php

namespace Database\Seeders;

use App\Models\ParetoClassification;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ParetoClassificationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $classifications = [
            ['name' => 'Fijos', 'percentage' => 50, 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Variables', 'percentage' => 30, 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Ahorro', 'percentage' => 20, 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ];
        ParetoClassification::insert($classifications);
    }
}
