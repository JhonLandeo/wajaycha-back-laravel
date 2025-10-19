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
        $pareto = DB::table('pareto_classifications')->pluck('id', 'name');

        DB::table('categories')->insert([
            // Fijos
            ['name' => 'Vivienda', 'pareto_classification_id' => $pareto['Fijos'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Telefonia', 'pareto_classification_id' => $pareto['Fijos'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Servicios básicos', 'pareto_classification_id' => $pareto['Fijos'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Seguros', 'pareto_classification_id' => $pareto['Fijos'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            // Variables
            ['name' => 'Salario', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Transporte', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Alimentación', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Entretenimiento', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Prestamos', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Salud', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Vestimenta', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Donaciones', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Favores', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Educacion', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Viajes', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Freelance', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Bienestar Personal', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Alimentacion fuera de casa', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Gastos para mi enamorada', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Intereses', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Transferencia interna', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Regalos y detalles', 'pareto_classification_id' => $pareto['Variables'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            // Ahorro
            ['name' => 'Inversiones', 'pareto_classification_id' => $pareto['Ahorro'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Fondo de emergencia', 'pareto_classification_id' => $pareto['Ahorro'], 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
