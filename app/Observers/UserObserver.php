<?php

namespace App\Observers;

use App\Models\Category;
use App\Models\ParetoClassification;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * Handle the User "created" event.
     */
    public function created(User $user): void
    {
        $defaultParetoClassifications = [
            // === CATEGORÍAS DE PRESUPUESTO (Suman 100%) ===

            // 1. NECESIDADES (Total 50%)
            // Gastos fijos obligatorios (alquiler, préstamos, internet, seguros)
            ['name' => 'Fijos', 'percentage' => 35, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()],
            // Gastos variables obligatorios (supermercado, luz, agua, transporte)
            ['name' => 'Variables Esenciales', 'percentage' => 15, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()],

            // 2. DESEOS (Total 30%)
            // Gastos 100% discrecionales (restaurantes, ocio, ropa)
            ['name' => 'Variables No Esenciales', 'percentage' => 30, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()],

            // 3. AHORRO (Total 20%)
            // Inversiones, fondo de emergencia
            ['name' => 'Ahorro', 'percentage' => 20, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()],


            // === CATEGORÍAS DE CLASIFICACIÓN (Sin presupuesto) ===

            // 4. DEUDA (Pago de TdC, etc. - El gasto ya se presupuestó)
            ['name' => 'Deuda', 'percentage' => 0, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()],

            // 5. TRANSFERENCIA (Movimiento neutral entre cuentas)
            ['name' => 'Transferencia', 'percentage' => 0, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()],

            // 6. INGRESOS (No son parte del presupuesto de gastos)
            ['name' => 'Ingreso Fijo', 'percentage' => 0, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Ingreso Variable', 'percentage' => 0, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()],
        ];
        ParetoClassification::insert($defaultParetoClassifications);

        $pareto = ParetoClassification::pluck('id', 'name')->toArray();
        $defaultCategories = [
            // --- TIPO: INGRESO ---
            [
                'name' => '📈 Ingresos', // Emoji actualizado para el padre
                'type' => 'income',
                'pareto_classification_id' => $pareto['Ingreso Fijo'],
                'children' => [
                    ['name' => '💵 Salario', 'type' => 'income', 'pareto_classification_id' => $pareto['Ingreso Fijo']],
                    ['name' => '💼 Freelance / Negocio', 'type' => 'income', 'pareto_classification_id' => $pareto['Ingreso Variable']],
                    ['name' => '📈 Intereses Ganados', 'type' => 'income', 'pareto_classification_id' => $pareto['Ingreso Variable']],
                    ['name' => '🔙 Reembolsos', 'type' => 'income', 'pareto_classification_id' => $pareto['Ingreso Variable']],
                    ['name' => '🎁 Regalos Recibidos', 'type' => 'income', 'pareto_classification_id' => $pareto['Ingreso Variable']],
                    ['name' => '🪙 Otros Ingresos', 'type' => 'income', 'pareto_classification_id' => $pareto['Ingreso Variable']],
                ]
            ],

            // --- TIPO: GASTO ---
            [
                'name' => '🏠 Hogar y Servicios',
                'type' => 'expense',
                'pareto_classification_id' => $pareto['Fijos'],
                'children' => [
                    ['name' => '🔑 Alquiler / Hipoteca', 'type' => 'expense', 'pareto_classification_id' => $pareto['Fijos']],
                    ['name' => '🌐 Internet', 'type' => 'expense', 'pareto_classification_id' => $pareto['Fijos']],
                    ['name' => '📱 Telefonía / Celular', 'type' => 'expense', 'pareto_classification_id' => $pareto['Fijos']],
                    ['name' => '💡 Luz', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables Esenciales']],
                    ['name' => '💧 Agua', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables Esenciales']],
                    ['name' => '🔥 Gas', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables Esenciales']],
                    ['name' => '🔧 Mantenimiento (Reparaciones)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables Esenciales']],
                    ['name' => '🛋️ Muebles y Electrodomésticos', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                ]
            ],
            [
                'name' => '🍽️ Alimentación',
                'type' => 'expense',
                'pareto_classification_id' => $pareto['Variables Esenciales'],
                'children' => [
                    ['name' => '🛒 Supermercado', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables Esenciales']],
                    ['name' => '🍜 Restaurantes y Cafés', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                    ['name' => '🛵 Delivery / Pedidos', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                ]
            ],
            [
                'name' => '🚗 Transporte',
                'type' => 'expense',
                'pareto_classification_id' => $pareto['Variables Esenciales'],
                'children' => [
                    ['name' => '🚌 Transporte Público', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables Esenciales']],
                    ['name' => '⛽ Combustible', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables Esenciales']],
                    ['name' => '🛠️ Mantenimiento Vehicular', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables Esenciales']],
                    ['name' => '🚕 Taxis y Apps', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                ]
            ],
            [
                'name' => '🛡️ Seguros',
                'type' => 'expense',
                'pareto_classification_id' => $pareto['Fijos'],
                'children' => [
                    ['name' => '❤️‍🩹 Seguro de Salud (Prima)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Fijos']],
                    ['name' => '🚗 Seguro Vehicular', 'type' => 'expense', 'pareto_classification_id' => $pareto['Fijos']],
                    ['name' => '👨‍👩‍👧‍👦 Seguro de Vida', 'type' => 'expense', 'pareto_classification_id' => $pareto['Fijos']],
                    ['name' => '🏡 Seguro de Hogar', 'type' => 'expense', 'pareto_classification_id' => $pareto['Fijos']],
                ]
            ],
            [
                'name' => '❤️ Vida Personal y Ocio',
                'type' => 'expense',
                'pareto_classification_id' => $pareto['Variables No Esenciales'],
                'children' => [
                    ['name' => '💊 Salud (Farmacia/Citas)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables Esenciales']],
                    ['name' => '📺 Suscripciones (Netflix, etc.)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Fijos']],
                    ['name' => '🏋️‍♀️ Gimnasio', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                    ['name' => '⚽ Deporte (Fútbol, etc.)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                    ['name' => '💅 Cuidado Personal', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                    ['name' => '👕 Ropa y Calzado', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                    ['name' => '🎬 Entretenimiento (Cine, etc.)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                    ['name' => '🎁 Regalos y Detalles (Dados)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                    ['name' => '🕊️ Donaciones', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                ]
            ],
            [
                'name' => '👨‍👩‍👧‍👦 Familia y Dependientes',
                'type' => 'expense',
                'pareto_classification_id' => $pareto['Variables Esenciales'],
                'children' => [
                    ['name' => '🎓 Hijos (Colegio/Universidad)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Fijos']],
                    ['name' => '👶 Hijos (Útiles/Ropa/Otros)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables Esenciales']],
                    ['name' => '🐾 Mascotas (Comida/Veterinario)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables Esenciales']],
                ]
            ],
            [
                'name' => '🎓 Educación y Viajes',
                'type' => 'expense',
                'pareto_classification_id' => $pareto['Variables No Esenciales'],
                'children' => [
                    ['name' => '📚 Educación (Cursos/Libros)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                    ['name' => '✈️ Viajes (Pasajes/Hoteles)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                ]
            ],
            [
                'name' => '💸 Finanzas (Gastos)',
                'type' => 'expense',
                'pareto_classification_id' => $pareto['Fijos'],
                'children' => [
                    ['name' => '🏦 Comisiones Bancarias', 'type' => 'expense', 'pareto_classification_id' => $pareto['Fijos']],
                    ['name' => '🧾 Pago de Préstamos (Cuotas)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Fijos']],
                    ['name' => '🏛️ Impuestos', 'type' => 'expense', 'pareto_classification_id' => $pareto['Fijos']],
                ]
            ],
            [
                'name' => '📎 Otros Gastos',
                'type' => 'expense',
                'pareto_classification_id' => $pareto['Variables No Esenciales'],
                'children' => [
                    ['name' => '✨ Gastos Únicos', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                    ['name' => '📦 Gastos Misceláneos', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                ]
            ],

            // --- TIPO: TRANSFERENCIA (NO SON GASTOS NI INGRESOS) ---
            [
                'name' => '🔵 Transferencias (Ocultas)',
                'type' => 'transfer',
                'pareto_classification_id' => $pareto['Transferencia'],
                'children' => [
                    ['name' => '💹 Inversiones', 'type' => 'transfer', 'pareto_classification_id' => $pareto['Ahorro']],
                    ['name' => '🛡️ Fondo de Emergencia', 'type' => 'transfer', 'pareto_classification_id' => $pareto['Ahorro']],
                    ['name' => '💳 Pago de Tarjeta de Crédito', 'type' => 'transfer', 'pareto_classification_id' => $pareto['Deuda']],
                    ['name' => '↔️ Entre Cuentas Propias', 'type' => 'transfer', 'pareto_classification_id' => $pareto['Transferencia']],
                    ['name' => '💸 Préstamos (a terceros)', 'type' => 'transfer', 'pareto_classification_id' => $pareto['Ahorro']],
                ]
            ],
        ];

        foreach ($defaultCategories as $group) {
            $parentCategory = Category::create([
                'user_id' => $user->id,
                'name' => $group['name'],
                'type' => $group['type'],
                'parent_id' => null,
                'pareto_classification_id' =>  $group['pareto_classification_id']
            ]);

            // 2. Itera y crea las Subcategorías (Hijos)
            foreach ($group['children'] as $child) {
                Category::create([
                    'user_id' => $user->id,
                    'name' => $child['name'],
                    'type' => $child['type'],
                    'parent_id' => $parentCategory->id,
                    'pareto_classification_id' =>  $group['pareto_classification_id']
                ]);
            }
        }
    }

    /**
     * Handle the User "updated" event.
     */
    public function updated(User $user): void
    {
        //
    }

    /**
     * Handle the User "deleted" event.
     */
    public function deleted(User $user): void
    {
        //
    }

    /**
     * Handle the User "restored" event.
     */
    public function restored(User $user): void
    {
        //
    }

    /**
     * Handle the User "force deleted" event.
     */
    public function forceDeleted(User $user): void
    {
        //
    }
}
