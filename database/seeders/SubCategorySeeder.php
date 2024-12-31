<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SubCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = DB::table('categories')->pluck('id', 'name');

        DB::table('subcategories')->insert([
            // Fijos
            ['name' => 'Vivienda', 'category_id' => $categories['Fijos'], 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Servicios básicos', 'category_id' => $categories['Fijos'], 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Seguros', 'category_id' => $categories['Fijos'], 'created_at' => now(), 'updated_at' => now()],
            // Variables
            ['name' => 'Salario', 'category_id' => $categories['Variables'], 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Transporte', 'category_id' => $categories['Variables'], 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Alimentación', 'category_id' => $categories['Variables'], 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Entretenimiento', 'category_id' => $categories['Variables'], 'created_at' => now(), 'updated_at' => now()],
            // Ahorro
            ['name' => 'Inversiones', 'category_id' => $categories['Ahorro'], 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Fondo de emergencia', 'category_id' => $categories['Ahorro'], 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
