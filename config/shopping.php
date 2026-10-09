<?php

declare(strict_types=1);

/**
 * The seeded grocery catalogue.
 *
 * This is data, following the `config/onboarding.php` precedent: changing a
 * product's name or unit should not mean reading control flow. Roughly 40
 * Peruvian staples, each with the canonical `App\Enums\Unit` value it is
 * bought in — the owner-confirmed q2 decision (state.yaml), chosen over a
 * smaller 10-15 item seed and over shipping no catalogue at all.
 *
 * `slug` is the seeder's upsert key (design.md, "The seeded catalogue"). It
 * must stay stable across renames of `name` — renaming "Palta" to
 * "Palta Fuerte" later is a data edit, not a new row, precisely because the
 * slug does not change with it.
 */
return [

    'grocery_category_name' => '🛒 Supermercado',

    'catalogue' => [
        ['slug' => 'papa-blanca', 'name' => 'Papa blanca', 'unit' => 'kg'],
        ['slug' => 'papa-amarilla', 'name' => 'Papa amarilla', 'unit' => 'kg'],
        ['slug' => 'camote', 'name' => 'Camote', 'unit' => 'kg'],
        ['slug' => 'yuca', 'name' => 'Yuca', 'unit' => 'kg'],
        ['slug' => 'arroz', 'name' => 'Arroz', 'unit' => 'kg'],
        ['slug' => 'azucar', 'name' => 'Azúcar', 'unit' => 'kg'],
        ['slug' => 'sal', 'name' => 'Sal', 'unit' => 'kg'],
        ['slug' => 'aceite-vegetal', 'name' => 'Aceite vegetal', 'unit' => 'l'],
        ['slug' => 'fideos', 'name' => 'Fideos', 'unit' => 'paquete'],
        ['slug' => 'lentejas', 'name' => 'Lentejas', 'unit' => 'kg'],
        ['slug' => 'frejol', 'name' => 'Frejol', 'unit' => 'kg'],
        ['slug' => 'garbanzo', 'name' => 'Garbanzo', 'unit' => 'kg'],
        ['slug' => 'pollo-entero', 'name' => 'Pollo entero', 'unit' => 'kg'],
        ['slug' => 'carne-de-res', 'name' => 'Carne de res', 'unit' => 'kg'],
        ['slug' => 'huevos', 'name' => 'Huevos', 'unit' => 'unidad'],
        ['slug' => 'leche-evaporada', 'name' => 'Leche evaporada', 'unit' => 'unidad'],
        ['slug' => 'queso-fresco', 'name' => 'Queso fresco', 'unit' => 'kg'],
        ['slug' => 'mantequilla', 'name' => 'Mantequilla', 'unit' => 'unidad'],
        ['slug' => 'pan', 'name' => 'Pan', 'unit' => 'unidad'],
        ['slug' => 'cebolla-roja', 'name' => 'Cebolla roja', 'unit' => 'kg'],
        ['slug' => 'tomate', 'name' => 'Tomate', 'unit' => 'kg'],
        ['slug' => 'ajo', 'name' => 'Ajo', 'unit' => 'kg'],
        ['slug' => 'limon', 'name' => 'Limón', 'unit' => 'kg'],
        ['slug' => 'palta', 'name' => 'Palta', 'unit' => 'unidad'],
        ['slug' => 'platano', 'name' => 'Plátano', 'unit' => 'kg'],
        ['slug' => 'platano-de-freir', 'name' => 'Plátano de freír', 'unit' => 'kg'],
        ['slug' => 'zanahoria', 'name' => 'Zanahoria', 'unit' => 'kg'],
        ['slug' => 'choclo', 'name' => 'Choclo', 'unit' => 'unidad'],
        ['slug' => 'culantro', 'name' => 'Culantro', 'unit' => 'atado'],
        ['slug' => 'perejil', 'name' => 'Perejil', 'unit' => 'atado'],
        ['slug' => 'apio', 'name' => 'Apio', 'unit' => 'atado'],
        ['slug' => 'rocoto', 'name' => 'Rocoto', 'unit' => 'kg'],
        ['slug' => 'aji-amarillo', 'name' => 'Ají amarillo', 'unit' => 'kg'],
        ['slug' => 'pescado-fresco', 'name' => 'Pescado fresco', 'unit' => 'kg'],
        ['slug' => 'atun-en-lata', 'name' => 'Atún en lata', 'unit' => 'unidad'],
        ['slug' => 'cafe-molido', 'name' => 'Café molido', 'unit' => 'paquete'],
        ['slug' => 'avena', 'name' => 'Avena', 'unit' => 'paquete'],
        ['slug' => 'quinua', 'name' => 'Quinua', 'unit' => 'kg'],
        ['slug' => 'manzana', 'name' => 'Manzana', 'unit' => 'kg'],
        ['slug' => 'naranja', 'name' => 'Naranja', 'unit' => 'kg'],
    ],

];
