<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ParetoClassification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $pareto = ParetoClassification::pluck('id', 'name')->toArray();
        $categories = [
            // Fijos
            ['name' => 'Vivienda', 'pareto_name' => 'Fijos', 'type' => 'expense'],
            ['name' => 'Telefonia', 'pareto_name' => 'Fijos', 'type' => 'expense'],
            ['name' => 'Servicios básicos', 'pareto_name' => 'Fijos', 'type' => 'expense'],
            ['name' => 'Seguros', 'pareto_name' => 'Fijos', 'type' => 'expense'],
            // Variables
            ['name' => 'Salario', 'pareto_name' => 'Variables', 'type' => 'income'],
            ['name' => 'Transporte', 'pareto_name' => 'Variables', 'type' => 'expense'],
            ['name' => 'Alimentación', 'pareto_name' => 'Variables', 'type' => 'expense'],
            ['name' => 'Entretenimiento', 'pareto_name' => 'Variables', 'type' => 'expense'],
            ['name' => 'Prestamos', 'pareto_name' => 'Variables', 'type' => 'expense'],
            ['name' => 'Salud', 'pareto_name' => 'Variables', 'type' => 'expense'],
            ['name' => 'Vestimenta', 'pareto_name' => 'Variables', 'type' => 'expense'],
            ['name' => 'Donaciones', 'pareto_name' => 'Variables', 'type' => 'expense'],
            ['name' => 'Favores', 'pareto_name' => 'Variables', 'type' => 'expense'],
            ['name' => 'Educacion', 'pareto_name' => 'Variables', 'type' => 'expense'],
            ['name' => 'Viajes', 'pareto_name' => 'Variables', 'type' => 'expense'],
            ['name' => 'Freelance', 'pareto_name' => 'Variables', 'type' => 'income'],
            ['name' => 'Bienestar Personal', 'pareto_name' => 'Variables', 'type' => 'expense'],
            ['name' => 'Alimentacion fuera de casa', 'pareto_name' => 'Variables', 'type' => 'expense'],
            ['name' => 'Gastos para mi enamorada', 'pareto_name' => 'Variables', 'type' => 'expense'],
            ['name' => 'Intereses', 'pareto_name' => 'Variables', 'type' => 'income'],
            ['name' => 'Transferencia interna', 'pareto_name' => 'Variables', 'type' => 'transfer'],
            ['name' => 'Regalos y detalles', 'pareto_name' => 'Variables', 'type' => 'expense'],
            // Ahorro
            ['name' => 'Inversiones', 'pareto_name' => 'Ahorro', 'type' => 'transfer'],
            ['name' => 'Fondo de emergencia', 'pareto_name' => 'Ahorro', 'type' => 'transfer'],
        ];

        foreach ($categories as $catData) {
            $category = Category::create([
                'name' => $catData['name'],
                'user_id' => 1,
                'type' => $catData['type'],
                'monthly_budget' => 1000,
            ]);

            if (isset($pareto[$catData['pareto_name']])) {
                DB::table('category_pareto_assignments')->insert([
                    'category_id' => $category->id,
                    'pareto_classification_id' => $pareto[$catData['pareto_name']],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
