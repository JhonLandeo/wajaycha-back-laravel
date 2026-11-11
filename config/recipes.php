<?php

declare(strict_types=1);

/**
 * Catálogo inicial de recetas compartidas.
 *
 * Los ingredientes se escriben con el slug del producto, no con su id: el id
 * depende del orden de siembra, el slug es estable. Las cantidades están en la
 * unidad canónica del producto para que el catálogo no dependa de conversiones
 * que todavía no necesita.
 */
return [
    'catalogue' => [
        [
            'slug' => 'arroz-con-pollo',
            'name' => 'Arroz con pollo',
            'base_servings' => 2,
            'meal_types' => ['lunch', 'dinner'],
            'prep_minutes' => 40,
            'ingredients' => [
                ['product' => 'pollo-entero', 'quantity' => 0.5, 'unit' => 'kg'],
                ['product' => 'arroz', 'quantity' => 0.25, 'unit' => 'kg'],
                ['product' => 'cebolla-roja', 'quantity' => 0.1, 'unit' => 'kg'],
                ['product' => 'ajo', 'quantity' => 0.01, 'unit' => 'kg'],
                ['product' => 'aceite-vegetal', 'quantity' => 0.02, 'unit' => 'l'],
            ],
        ],
        [
            'slug' => 'lentejas-guisadas',
            'name' => 'Lentejas guisadas',
            'base_servings' => 2,
            'meal_types' => ['lunch', 'dinner'],
            'prep_minutes' => 35,
            'ingredients' => [
                ['product' => 'lentejas', 'quantity' => 0.25, 'unit' => 'kg'],
                ['product' => 'cebolla-roja', 'quantity' => 0.1, 'unit' => 'kg'],
                ['product' => 'tomate', 'quantity' => 0.15, 'unit' => 'kg'],
                ['product' => 'aceite-vegetal', 'quantity' => 0.02, 'unit' => 'l'],
            ],
        ],
        [
            'slug' => 'tallarin-saltado-de-pollo',
            'name' => 'Tallarín saltado de pollo',
            'base_servings' => 2,
            'meal_types' => ['lunch', 'dinner'],
            'prep_minutes' => 30,
            'ingredients' => [
                ['product' => 'fideos', 'quantity' => 1, 'unit' => 'paquete'],
                ['product' => 'pollo-entero', 'quantity' => 0.4, 'unit' => 'kg'],
                ['product' => 'tomate', 'quantity' => 0.15, 'unit' => 'kg'],
                ['product' => 'cebolla-roja', 'quantity' => 0.1, 'unit' => 'kg'],
                ['product' => 'aceite-vegetal', 'quantity' => 0.02, 'unit' => 'l'],
            ],
        ],
        [
            'slug' => 'tortilla-de-verduras',
            'name' => 'Tortilla de verduras',
            'base_servings' => 2,
            'meal_types' => ['breakfast', 'lunch', 'dinner'],
            'prep_minutes' => 20,
            'ingredients' => [
                ['product' => 'huevos', 'quantity' => 4, 'unit' => 'unidad'],
                ['product' => 'zanahoria', 'quantity' => 0.1, 'unit' => 'kg'],
                ['product' => 'apio', 'quantity' => 1, 'unit' => 'atado'],
                ['product' => 'aceite-vegetal', 'quantity' => 0.01, 'unit' => 'l'],
            ],
        ],
        [
            'slug' => 'avena-con-platano',
            'name' => 'Avena con plátano',
            'base_servings' => 2,
            'meal_types' => ['breakfast'],
            'prep_minutes' => 10,
            'ingredients' => [
                ['product' => 'avena', 'quantity' => 0.1, 'unit' => 'paquete'],
                ['product' => 'platano', 'quantity' => 0.2, 'unit' => 'kg'],
                ['product' => 'leche-evaporada', 'quantity' => 1, 'unit' => 'unidad'],
            ],
        ],
        [
            'slug' => 'sopa-de-verduras-con-pollo',
            'name' => 'Sopa de verduras con pollo',
            'base_servings' => 2,
            'meal_types' => ['dinner'],
            'prep_minutes' => 30,
            'ingredients' => [
                ['product' => 'pollo-entero', 'quantity' => 0.3, 'unit' => 'kg'],
                ['product' => 'zanahoria', 'quantity' => 0.15, 'unit' => 'kg'],
                ['product' => 'papa-blanca', 'quantity' => 0.2, 'unit' => 'kg'],
                ['product' => 'apio', 'quantity' => 1, 'unit' => 'atado'],
            ],
        ],
        [
            'slug' => 'frejoles-con-arroz',
            'name' => 'Frejoles con arroz',
            'base_servings' => 2,
            'meal_types' => ['lunch', 'dinner'],
            'prep_minutes' => 40,
            'ingredients' => [
                ['product' => 'frejol', 'quantity' => 0.25, 'unit' => 'kg'],
                ['product' => 'arroz', 'quantity' => 0.2, 'unit' => 'kg'],
                ['product' => 'cebolla-roja', 'quantity' => 0.1, 'unit' => 'kg'],
                ['product' => 'aceite-vegetal', 'quantity' => 0.02, 'unit' => 'l'],
            ],
        ],
        [
            'slug' => 'pescado-al-horno-con-papa',
            'name' => 'Pescado al horno con papa',
            'base_servings' => 2,
            'meal_types' => ['lunch', 'dinner'],
            'prep_minutes' => 35,
            'ingredients' => [
                ['product' => 'pescado-fresco', 'quantity' => 0.5, 'unit' => 'kg'],
                ['product' => 'papa-blanca', 'quantity' => 0.4, 'unit' => 'kg'],
                ['product' => 'limon', 'quantity' => 0.1, 'unit' => 'kg'],
                ['product' => 'aceite-vegetal', 'quantity' => 0.02, 'unit' => 'l'],
            ],
        ],
        [
            'slug' => 'ensalada-de-atun',
            'name' => 'Ensalada de atún',
            'base_servings' => 2,
            'meal_types' => ['dinner'],
            'prep_minutes' => 15,
            'ingredients' => [
                ['product' => 'atun-en-lata', 'quantity' => 2, 'unit' => 'unidad'],
                ['product' => 'tomate', 'quantity' => 0.15, 'unit' => 'kg'],
                ['product' => 'palta', 'quantity' => 1, 'unit' => 'unidad'],
                ['product' => 'limon', 'quantity' => 0.1, 'unit' => 'kg'],
            ],
        ],
        [
            'slug' => 'pan-con-palta-y-huevo',
            'name' => 'Pan con palta y huevo',
            'base_servings' => 2,
            'meal_types' => ['breakfast'],
            'prep_minutes' => 10,
            'ingredients' => [
                ['product' => 'pan', 'quantity' => 4, 'unit' => 'unidad'],
                ['product' => 'palta', 'quantity' => 1, 'unit' => 'unidad'],
                ['product' => 'huevos', 'quantity' => 2, 'unit' => 'unidad'],
            ],
        ],
    ],
];
