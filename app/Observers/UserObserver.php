<?php

namespace App\Observers;

use App\Models\Category;
use App\Models\ParetoClassification;
use App\Models\Tag;
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
        Log::info('Default Pareto Classifications: ' . json_encode($defaultParetoClassifications));
        ParetoClassification::insert($defaultParetoClassifications);

        $pareto = ParetoClassification::where('user_id', $user->id)->pluck('id', 'name')->toArray();
        $defaultCategories = [
            // ------------------------------------------------
            // 🟢 TIPO: INGRESO
            // ------------------------------------------------
            [
                'name' => '📈 Ingresos',
                'type' => 'income',
                'pareto_classification_id' => $pareto['Ingreso Fijo'],
                'children' => [
                    ['name' => '💵 Salario', 'type' => 'income', 'pareto_classification_id' => $pareto['Ingreso Fijo']],
                    ['name' => '💼 Freelance / Negocio', 'type' => 'income', 'pareto_classification_id' => $pareto['Ingreso Variable']],
                    ['name' => '📈 Intereses / Rentas', 'type' => 'income', 'pareto_classification_id' => $pareto['Ingreso Variable']],
                    ['name' => '🔙 Reembolsos', 'type' => 'income', 'pareto_classification_id' => $pareto['Ingreso Variable']],
                    ['name' => '🎁 Regalos Recibidos', 'type' => 'income', 'pareto_classification_id' => $pareto['Ingreso Variable']],
                    ['name' => '💸 Préstamos Recibidos (Deuda)', 'type' => 'income', 'pareto_classification_id' => $pareto['Ingreso Variable']],
                    ['name' => '🪙 Otros Ingresos', 'type' => 'income', 'pareto_classification_id' => $pareto['Ingreso Variable']],
                ]
            ],

            // ------------------------------------------------
            // 🔴 TIPO: GASTO
            // ------------------------------------------------
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
                    ['name' => '🔥 Gas', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables Esenciales']], // Balón de gas
                    ['name' => '🔧 Mantenimiento Hogar', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables Esenciales']],
                    ['name' => '🧹 Artículos de Limpieza', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables Esenciales']], // Tu detergente, etc.
                    ['name' => '🛋️ Muebles y Deco', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
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
                'name' => '❤️ Vida Personal y Ocio',
                'type' => 'expense',
                'pareto_classification_id' => $pareto['Variables No Esenciales'],
                'children' => [
                    ['name' => '💊 Salud (Farmacia/Citas)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables Esenciales']],
                    ['name' => '📺 Suscripciones (Netflix)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Fijos']],
                    ['name' => '⚽ Deporte y Gimnasio', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']], // Rehidratante va aquí
                    ['name' => '💅 Cuidado Personal', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                    ['name' => '🎬 Entretenimiento (Cine)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                    ['name' => '🎁 Regalos (Dados)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']], // Gastos enamorada (sin retorno)
                    ['name' => '🕊️ Donaciones', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                ]
            ],
            [
                'name' => '🛍️ Compras y Tecnología',
                'type' => 'expense',
                'pareto_classification_id' => $pareto['Variables No Esenciales'],
                'children' => [
                    ['name' => '👕 Ropa y Calzado', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                    ['name' => '💻 Tecnología y Electrónicos', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']], // Tu laptop va aquí
                    ['name' => '📦 Gastos Misceláneos', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                ]
            ],
            [
                'name' => '👨‍👩‍👧‍👦 Familia y Dependientes',
                'type' => 'expense',
                'pareto_classification_id' => $pareto['Variables Esenciales'],
                'children' => [
                    ['name' => '🎓 Hijos (Colegio/Uni)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Fijos']],
                    ['name' => '👶 Hijos (Ropa/Útiles)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables Esenciales']],
                    ['name' => '🐾 Mascotas', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables Esenciales']],
                ]
            ],
            [
                'name' => '🎓 Educación y Viajes',
                'type' => 'expense',
                'pareto_classification_id' => $pareto['Variables No Esenciales'],
                'children' => [
                    ['name' => '📚 Educación (Cursos)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']],
                    ['name' => '✈️ Viajes y Turismo', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables No Esenciales']], // Paseos grandes
                ]
            ],
            [
                'name' => '💸 Finanzas (Gastos)',
                'type' => 'expense',
                'pareto_classification_id' => $pareto['Fijos'],
                'children' => [
                    ['name' => '🏦 Comisiones Bancarias', 'type' => 'expense', 'pareto_classification_id' => $pareto['Fijos']],
                    ['name' => '🧾 Intereses de Deuda', 'type' => 'expense', 'pareto_classification_id' => $pareto['Fijos']],
                    ['name' => '🏛️ Impuestos', 'type' => 'expense', 'pareto_classification_id' => $pareto['Fijos']],
                ]
            ],

            // ------------------------------------------------
            // 🔵 TIPO: TRANSFERENCIA (OCULTAS)
            // ------------------------------------------------
            [
                'name' => '🔵 Transferencias (Ocultas)',
                'type' => 'transfer',
                'pareto_classification_id' => $pareto['Transferencia'],
                'children' => [
                    ['name' => '💳 Pago de Tarjeta de Crédito', 'type' => 'transfer', 'pareto_classification_id' => $pareto['Deuda']], // Pagar la TC
                    ['name' => '💵 Pago de Capital (Préstamos)', 'type' => 'transfer', 'pareto_classification_id' => $pareto['Deuda']], // Pagar cuota al banco
                    ['name' => '↔️ Entre Cuentas Propias', 'type' => 'transfer', 'pareto_classification_id' => $pareto['Transferencia']], // El favor de efectivo
                    ['name' => '💸 Préstamos (a terceros)', 'type' => 'transfer', 'pareto_classification_id' => $pareto['Transferencia']], // Dinero que prestas
                    ['name' => '🔙 Favores (Por Reembolsar)', 'type' => 'transfer', 'pareto_classification_id' => $pareto['Transferencia']], // Favores que te van a pagar
                ]
            ],

            // ------------------------------------------------
            // 🟡 TIPO: AHORRO
            // ------------------------------------------------
            [
                'name' => '🛡️ Ahorro',
                'type' => 'transfer',
                'pareto_classification_id' => $pareto['Ahorro'],
                'children' => [
                    ['name' => '💹 Inversiones', 'type' => 'transfer', 'pareto_classification_id' => $pareto['Ahorro']],
                    ['name' => '🛡️ Fondo de Emergencia', 'type' => 'transfer', 'pareto_classification_id' => $pareto['Ahorro']],
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

        $defaultTags = [
            // Por Persona
            'Pareja',
            'Familia',
            'Amigos',
            'Mascotas',
            // Por Evento
            'Vacaciones',
            'Cumpleaños',
            'Aniversario',
            'Celebración',
            // Por Contexto
            'Trabajo',
            'Reembolsable',
            'Gasto Hormiga'
        ];

        // Prepara un array para una inserción masiva (más rápido)
        $tagsToInsert = [];
        $now = now();

        foreach ($defaultTags as $tagName) {
            $tagsToInsert[] = [
                'user_id' => $user->id,
                'name' => $tagName,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Inserta todos los tags en una sola consulta si el usuario no tiene ninguno
        if ($user->tags()->count() === 0) {
            Tag::insert($tagsToInsert);
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
