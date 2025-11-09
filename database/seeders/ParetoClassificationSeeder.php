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
            // === CATEGORÍAS DE PRESUPUESTO (Suman 100%) ===

            // 1. NECESIDADES (Total 50%)
            // Gastos fijos obligatorios (alquiler, préstamos, internet, seguros)
            ['name' => 'Fijos', 'percentage' => 35, 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            // Gastos variables obligatorios (supermercado, luz, agua, transporte)
            ['name' => 'Variables Esenciales', 'percentage' => 15, 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],

            // 2. DESEOS (Total 30%)
            // Gastos 100% discrecionales (restaurantes, ocio, ropa)
            ['name' => 'Variables No Esenciales', 'percentage' => 30, 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],

            // 3. AHORRO (Total 20%)
            // Inversiones, fondo de emergencia
            ['name' => 'Ahorro', 'percentage' => 20, 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],


            // === CATEGORÍAS DE CLASIFICACIÓN (Sin presupuesto) ===

            // 4. DEUDA (Pago de TdC, etc. - El gasto ya se presupuestó)
            ['name' => 'Deuda', 'percentage' => 0, 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],

            // 5. TRANSFERENCIA (Movimiento neutral entre cuentas)
            ['name' => 'Transferencia', 'percentage' => 0, 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],

            // 6. INGRESOS (No son parte del presupuesto de gastos)
            ['name' => 'Ingreso Fijo', 'percentage' => 0, 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Ingreso Variable', 'percentage' => 0, 'user_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ];
        ParetoClassification::insert($classifications);
    }
}
