<?php

declare(strict_types=1);

/**
 * What a new account starts with.
 *
 * This is data, and it used to be a hundred and forty lines of it inlined in
 * `UserObserver::created()`, where changing a category name meant editing a method that
 * also opened transactions and wrote pivot rows. Editing a list should not require
 * reading control flow.
 *
 * `band` names a Pareto classification from `pareto_classifications` below rather than
 * an id, because the ids do not exist until the classifications are inserted for this
 * user. `null` means the category is deliberately outside the Pareto reading — see
 * `SeedDefaultWorkspaceAction` and the transfer rule it documents.
 */
return [

    'pareto_classifications' => [
        ['name' => 'Fijos', 'percentage' => 35],
        ['name' => 'Variables', 'percentage' => 45],
        ['name' => 'Ahorro', 'percentage' => 20],
    ],

    'categories' => [
        // ------------------------------------------------
        // 🟢 TIPO: INGRESO
        // ------------------------------------------------
        [
            'name' => '📈 Ingresos',
            'type' => 'income',
            'band' => null,
            'children' => [
                ['name' => '💵 Salario', 'type' => 'income', 'band' => null],
                ['name' => '💼 Freelance / Negocio', 'type' => 'income', 'band' => null],
                ['name' => '📈 Intereses / Rentas', 'type' => 'income', 'band' => null],
                ['name' => '🔙 Reembolsos', 'type' => 'income', 'band' => null],
                ['name' => '🎁 Regalos Recibidos', 'type' => 'income', 'band' => null],
                ['name' => '💸 Préstamos Recibidos (Deuda)', 'type' => 'income', 'band' => null],
                ['name' => '🪙 Otros Ingresos', 'type' => 'income', 'band' => null],
            ],
        ],

        // ------------------------------------------------
        // 🔴 TIPO: GASTO
        // ------------------------------------------------
        [
            'name' => '🏠 Hogar y Servicios',
            'type' => 'expense',
            'band' => 'Fijos',
            'children' => [
                ['name' => '🔑 Alquiler / Hipoteca', 'type' => 'expense', 'band' => 'Fijos'],
                ['name' => '🌐 Internet', 'type' => 'expense', 'band' => 'Fijos'],
                ['name' => '📱 Telefonía / Celular', 'type' => 'expense', 'band' => 'Fijos'],
                ['name' => '💡 Luz', 'type' => 'expense', 'band' => 'Variables'],
                ['name' => '💧 Agua', 'type' => 'expense', 'band' => 'Variables'],
                ['name' => '🔥 Gas', 'type' => 'expense', 'band' => 'Variables'],
                ['name' => '🔧 Mantenimiento Hogar', 'type' => 'expense', 'band' => 'Variables'],
                ['name' => '🧹 Artículos de Limpieza', 'type' => 'expense', 'band' => 'Variables'],
                ['name' => ' Couch Muebles y Deco', 'type' => 'expense', 'band' => 'Variables'],
            ],
        ],
        [
            'name' => '🍽️ Alimentación',
            'type' => 'expense',
            'band' => 'Variables',
            'children' => [
                ['name' => '🛒 Supermercado', 'type' => 'expense', 'band' => 'Variables'],
                ['name' => '🍜 Restaurantes y Cafés', 'type' => 'expense', 'band' => 'Variables'],
                ['name' => '🛵 Delivery / Pedidos', 'type' => 'expense', 'band' => 'Variables'],
            ],
        ],
        [
            'name' => '🚗 Transporte',
            'type' => 'expense',
            'band' => 'Variables',
            'children' => [
                ['name' => '🚌 Transporte Público', 'type' => 'expense', 'band' => 'Variables'],
                ['name' => '⛽ Combustible', 'type' => 'expense', 'band' => 'Variables'],
                ['name' => '🛠️ Mantenimiento Vehicular', 'type' => 'expense', 'band' => 'Variables'],
                ['name' => '🚕 Taxis y Apps', 'type' => 'expense', 'band' => 'Variables'],
            ],
        ],
        [
            'name' => '❤️ Vida Personal y Ocio',
            'type' => 'expense',
            'band' => 'Variables',
            'children' => [
                ['name' => '💊 Salud (Farmacia/Citas)', 'type' => 'expense', 'band' => 'Variables'],
                ['name' => '📺 Suscripciones (Netflix)', 'type' => 'expense', 'band' => 'Fijos'],
                ['name' => '⚽ Deporte y Gimnasio', 'type' => 'expense', 'band' => 'Variables'],
                ['name' => '💅 Cuidado Personal', 'type' => 'expense', 'band' => 'Variables'],
                ['name' => '🎬 Entretenimiento (Cine)', 'type' => 'expense', 'band' => 'Variables'],
                ['name' => '🎁 Regalos (Dados)', 'type' => 'expense', 'band' => 'Variables'],
                ['name' => '🕊️ Donaciones', 'type' => 'expense', 'band' => 'Variables'],
            ],
        ],
        [
            'name' => '🛍️ Compras y Tecnología',
            'type' => 'expense',
            'band' => 'Variables',
            'children' => [
                ['name' => '👕 Ropa y Calzado', 'type' => 'expense', 'band' => 'Variables'],
                ['name' => '💻 Tecnología y Electrónicos', 'type' => 'expense', 'band' => 'Variables'],
                ['name' => '📦 Gastos Misceláneos', 'type' => 'expense', 'band' => 'Variables'],
            ],
        ],
        [
            'name' => '👨‍👩‍👧‍👦 Familia y Dependientes',
            'type' => 'expense',
            'band' => 'Variables',
            'children' => [
                ['name' => '🎓 Hijos (Colegio/Uni)', 'type' => 'expense', 'band' => 'Fijos'],
                ['name' => '👶 Hijos (Ropa/Útiles)', 'type' => 'expense', 'band' => 'Variables'],
                ['name' => '🐾 Mascotas', 'type' => 'expense', 'band' => 'Variables'],
            ],
        ],
        [
            'name' => '🎓 Educación y Viajes',
            'type' => 'expense',
            'band' => 'Variables',
            'children' => [
                ['name' => '📚 Educación (Cursos)', 'type' => 'expense', 'band' => 'Variables'],
                ['name' => '✈️ Viajes y Turismo', 'type' => 'expense', 'band' => 'Variables'],
            ],
        ],
        [
            'name' => '💸 Finanzas (Gastos)',
            'type' => 'expense',
            'band' => 'Fijos',
            'children' => [
                ['name' => '🏦 Comisiones Bancarias', 'type' => 'expense', 'band' => 'Fijos'],
                ['name' => '🧾 Intereses de Deuda', 'type' => 'expense', 'band' => 'Fijos'],
                ['name' => '🏛️ Impuestos', 'type' => 'expense', 'band' => 'Fijos'],
            ],
        ],

        // ------------------------------------------------
        // 🔵 TIPO: TRANSFERENCIA (OCULTAS)
        // ------------------------------------------------
        [
            'name' => '🔵 Transferencias (Ocultas)',
            'type' => 'transfer',
            'band' => null,
            'children' => [
                ['name' => '💳 Pago de Tarjeta de Crédito', 'type' => 'transfer', 'band' => 'Fijos'],
                ['name' => '💵 Pago de Capital (Préstamos)', 'type' => 'transfer', 'band' => 'Fijos'],
                ['name' => '↔️ Entre Cuentas Propias', 'type' => 'transfer', 'band' => null],
                ['name' => '💸 Préstamos (a terceros)', 'type' => 'transfer', 'band' => null],
                ['name' => '🔙 Favores (Por Reembolsar)', 'type' => 'transfer', 'band' => null],
            ],
        ],

        // ------------------------------------------------
        // 🟡 TIPO: AHORRO
        // ------------------------------------------------
        [
            'name' => '🛡️ Ahorro',
            'type' => 'transfer',
            'band' => 'Ahorro',
            'children' => [
                ['name' => '💹 Inversiones', 'type' => 'transfer', 'band' => 'Ahorro'],
                ['name' => '🛡️ Fondo de Emergencia', 'type' => 'transfer', 'band' => 'Ahorro'],
            ],
        ],
    ],

    'tags' => [
        'Pareja', 'Familia', 'Amigos', 'Mascotas',
        'Vacaciones', 'Cumpleaños', 'Aniversario', 'Celebración',
        'Trabajo', 'Reembolsable', 'Gasto Hormiga',
    ],

];
