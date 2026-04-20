<?php

declare(strict_types=1);

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
            ['name' => 'Fijos', 'percentage' => 35, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Variables', 'percentage' => 45, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Ahorro', 'percentage' => 20, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()],
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
                'pareto_classification_id' => null,
                'children' => [
                    ['name' => '💵 Salario', 'type' => 'income', 'pareto_classification_id' => null],
                    ['name' => '💼 Freelance / Negocio', 'type' => 'income', 'pareto_classification_id' => null],
                    ['name' => '📈 Intereses / Rentas', 'type' => 'income', 'pareto_classification_id' => null],
                    ['name' => '🔙 Reembolsos', 'type' => 'income', 'pareto_classification_id' => null],
                    ['name' => '🎁 Regalos Recibidos', 'type' => 'income', 'pareto_classification_id' => null],
                    ['name' => '💸 Préstamos Recibidos (Deuda)', 'type' => 'income', 'pareto_classification_id' => null],
                    ['name' => '🪙 Otros Ingresos', 'type' => 'income', 'pareto_classification_id' => null],
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
                    ['name' => '💡 Luz', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                    ['name' => '💧 Agua', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                    ['name' => '🔥 Gas', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                    ['name' => '🔧 Mantenimiento Hogar', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                    ['name' => '🧹 Artículos de Limpieza', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                    ['name' => ' Couch Muebles y Deco', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                ]
            ],
            [
                'name' => '🍽️ Alimentación',
                'type' => 'expense',
                'pareto_classification_id' => $pareto['Variables'],
                'children' => [
                    ['name' => '🛒 Supermercado', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                    ['name' => '🍜 Restaurantes y Cafés', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                    ['name' => '🛵 Delivery / Pedidos', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                ]
            ],
            [
                'name' => '🚗 Transporte',
                'type' => 'expense',
                'pareto_classification_id' => $pareto['Variables'],
                'children' => [
                    ['name' => '🚌 Transporte Público', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                    ['name' => '⛽ Combustible', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                    ['name' => '🛠️ Mantenimiento Vehicular', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                    ['name' => '🚕 Taxis y Apps', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                ]
            ],
            [
                'name' => '❤️ Vida Personal y Ocio',
                'type' => 'expense',
                'pareto_classification_id' => $pareto['Variables'],
                'children' => [
                    ['name' => '💊 Salud (Farmacia/Citas)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                    ['name' => '📺 Suscripciones (Netflix)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Fijos']],
                    ['name' => '⚽ Deporte y Gimnasio', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                    ['name' => '💅 Cuidado Personal', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                    ['name' => '🎬 Entretenimiento (Cine)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                    ['name' => '🎁 Regalos (Dados)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                    ['name' => '🕊️ Donaciones', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                ]
            ],
            [
                'name' => '🛍️ Compras y Tecnología',
                'type' => 'expense',
                'pareto_classification_id' => $pareto['Variables'],
                'children' => [
                    ['name' => '👕 Ropa y Calzado', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                    ['name' => '💻 Tecnología y Electrónicos', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                    ['name' => '📦 Gastos Misceláneos', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                ]
            ],
            [
                'name' => '👨‍👩‍👧‍👦 Familia y Dependientes',
                'type' => 'expense',
                'pareto_classification_id' => $pareto['Variables'],
                'children' => [
                    ['name' => '🎓 Hijos (Colegio/Uni)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Fijos']],
                    ['name' => '👶 Hijos (Ropa/Útiles)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                    ['name' => '🐾 Mascotas', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                ]
            ],
            [
                'name' => '🎓 Educación y Viajes',
                'type' => 'expense',
                'pareto_classification_id' => $pareto['Variables'],
                'children' => [
                    ['name' => '📚 Educación (Cursos)', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
                    ['name' => '✈️ Viajes y Turismo', 'type' => 'expense', 'pareto_classification_id' => $pareto['Variables']],
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
                'pareto_classification_id' => null,
                'children' => [
                    ['name' => '💳 Pago de Tarjeta de Crédito', 'type' => 'transfer', 'pareto_classification_id' => $pareto['Fijos']],
                    ['name' => '💵 Pago de Capital (Préstamos)', 'type' => 'transfer', 'pareto_classification_id' => $pareto['Fijos']],
                    ['name' => '↔️ Entre Cuentas Propias', 'type' => 'transfer', 'pareto_classification_id' => null],
                    ['name' => '💸 Préstamos (a terceros)', 'type' => 'transfer', 'pareto_classification_id' => null],
                    ['name' => '🔙 Favores (Por Reembolsar)', 'type' => 'transfer', 'pareto_classification_id' => null],
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
            ]);

            if ($group['pareto_classification_id']) {
                DB::table('category_pareto_assignments')->insert([
                    'category_id' => $parentCategory->id,
                    'pareto_classification_id' => (int) $group['pareto_classification_id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 2. Itera y crea las Subcategorías (Hijos)
            foreach ($group['children'] as $child) {
                $childCategory = Category::create([
                    'user_id' => $user->id,
                    'name' => $child['name'],
                    'type' => $child['type'],
                    'parent_id' => $parentCategory->id,
                ]);

                if ($child['pareto_classification_id']) {
                    DB::table('category_pareto_assignments')->insert([
                        'category_id' => $childCategory->id,
                        'pareto_classification_id' => (int) $child['pareto_classification_id'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        $defaultTags = [
            'Pareja', 'Familia', 'Amigos', 'Mascotas',
            'Vacaciones', 'Cumpleaños', 'Aniversario', 'Celebración',
            'Trabajo', 'Reembolsable', 'Gasto Hormiga'
        ];

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

        if ($user->tags()->count() === 0) {
            Tag::insert($tagsToInsert);
        }
    }

    public function updated(User $user): void {}
    public function deleted(User $user): void {}
    public function restored(User $user): void {}
    public function forceDeleted(User $user): void {}
}
