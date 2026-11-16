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
 *
 * Nutrition is approximate and per canonical unit, not per 100 g (ADR-0031).
 * For `kg` and `l` products the numbers are therefore ten times the familiar
 * per-100-g table; a recipe that declares 200 g converts to 0.2 kg before
 * multiplying. For discrete units (`unidad`, `atado`, `paquete`) the number is
 * per piece, with the usual package size assumed. These values are a planning
 * aid, not a medical or dietary prescription.
 */
return [

    'grocery_category_name' => '🛒 Supermercado',

    'catalogue' => [
        ['slug' => 'papa-blanca', 'name' => 'Papa blanca', 'unit' => 'kg', 'calories_kcal' => 770, 'protein_g' => 20, 'carbs_g' => 175, 'fat_g' => 1],
        ['slug' => 'papa-amarilla', 'name' => 'Papa amarilla', 'unit' => 'kg', 'calories_kcal' => 900, 'protein_g' => 20, 'carbs_g' => 200, 'fat_g' => 1],
        ['slug' => 'camote', 'name' => 'Camote', 'unit' => 'kg', 'calories_kcal' => 860, 'protein_g' => 16, 'carbs_g' => 200, 'fat_g' => 1],
        ['slug' => 'yuca', 'name' => 'Yuca', 'unit' => 'kg', 'calories_kcal' => 1600, 'protein_g' => 14, 'carbs_g' => 380, 'fat_g' => 3],
        ['slug' => 'arroz', 'name' => 'Arroz', 'unit' => 'kg', 'calories_kcal' => 3600, 'protein_g' => 70, 'carbs_g' => 780, 'fat_g' => 8],
        ['slug' => 'azucar', 'name' => 'Azúcar', 'unit' => 'kg', 'calories_kcal' => 4000, 'protein_g' => 0, 'carbs_g' => 1000, 'fat_g' => 0],
        ['slug' => 'sal', 'name' => 'Sal', 'unit' => 'kg', 'calories_kcal' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0],
        ['slug' => 'aceite-vegetal', 'name' => 'Aceite vegetal', 'unit' => 'l', 'calories_kcal' => 8000, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 900],
        ['slug' => 'fideos', 'name' => 'Fideos', 'unit' => 'paquete', 'calories_kcal' => 1750, 'protein_g' => 60, 'carbs_g' => 360, 'fat_g' => 7],
        ['slug' => 'lentejas', 'name' => 'Lentejas', 'unit' => 'kg', 'calories_kcal' => 3500, 'protein_g' => 250, 'carbs_g' => 600, 'fat_g' => 10],
        ['slug' => 'frejol', 'name' => 'Frejol', 'unit' => 'kg', 'calories_kcal' => 3300, 'protein_g' => 220, 'carbs_g' => 600, 'fat_g' => 12],
        ['slug' => 'garbanzo', 'name' => 'Garbanzo', 'unit' => 'kg', 'calories_kcal' => 3600, 'protein_g' => 200, 'carbs_g' => 600, 'fat_g' => 60],
        ['slug' => 'pollo-entero', 'name' => 'Pollo entero', 'unit' => 'kg', 'calories_kcal' => 1650, 'protein_g' => 270, 'carbs_g' => 0, 'fat_g' => 60],
        ['slug' => 'carne-de-res', 'name' => 'Carne de res', 'unit' => 'kg', 'calories_kcal' => 2500, 'protein_g' => 260, 'carbs_g' => 0, 'fat_g' => 150],
        ['slug' => 'carne-de-cerdo', 'name' => 'Carne de cerdo', 'unit' => 'kg', 'calories_kcal' => 2400, 'protein_g' => 270, 'carbs_g' => 0, 'fat_g' => 140],
        ['slug' => 'papa-seca', 'name' => 'Papa seca', 'unit' => 'kg', 'calories_kcal' => 3500, 'protein_g' => 50, 'carbs_g' => 800, 'fat_g' => 5],
        ['slug' => 'sillao', 'name' => 'Sillao', 'unit' => 'l', 'calories_kcal' => 530, 'protein_g' => 80, 'carbs_g' => 49, 'fat_g' => 0],
        ['slug' => 'mondongo', 'name' => 'Mondongo', 'unit' => 'kg', 'calories_kcal' => 1000, 'protein_g' => 150, 'carbs_g' => 0, 'fat_g' => 40],
        ['slug' => 'oregano', 'name' => 'Orégano', 'unit' => 'paquete', 'calories_kcal' => 50, 'protein_g' => 2, 'carbs_g' => 10, 'fat_g' => 2],
        ['slug' => 'mariscos', 'name' => 'Mariscos', 'unit' => 'kg', 'calories_kcal' => 800, 'protein_g' => 150, 'carbs_g' => 20, 'fat_g' => 10],
        ['slug' => 'hierbabuena', 'name' => 'Hierbabuena', 'unit' => 'atado', 'calories_kcal' => 30, 'protein_g' => 1, 'carbs_g' => 5, 'fat_g' => 0],
        ['slug' => 'corazon-de-res', 'name' => 'Corazón de res', 'unit' => 'kg', 'calories_kcal' => 1100, 'protein_g' => 180, 'carbs_g' => 0, 'fat_g' => 40],
        ['slug' => 'huacatay', 'name' => 'Huacatay', 'unit' => 'atado', 'calories_kcal' => 30, 'protein_g' => 2, 'carbs_g' => 5, 'fat_g' => 0],
        ['slug' => 'harina', 'name' => 'Harina', 'unit' => 'kg', 'calories_kcal' => 3600, 'protein_g' => 100, 'carbs_g' => 760, 'fat_g' => 10],
        ['slug' => 'huevos', 'name' => 'Huevos', 'unit' => 'unidad', 'calories_kcal' => 70, 'protein_g' => 6, 'carbs_g' => 0.5, 'fat_g' => 5],
        ['slug' => 'leche-evaporada', 'name' => 'Leche evaporada', 'unit' => 'unidad', 'calories_kcal' => 400, 'protein_g' => 20, 'carbs_g' => 30, 'fat_g' => 25],
        ['slug' => 'yogur-griego', 'name' => 'Yogur griego', 'unit' => 'unidad', 'calories_kcal' => 120, 'protein_g' => 10, 'carbs_g' => 8, 'fat_g' => 5],
        ['slug' => 'queso-fresco', 'name' => 'Queso fresco', 'unit' => 'kg', 'calories_kcal' => 2500, 'protein_g' => 200, 'carbs_g' => 30, 'fat_g' => 180],
        ['slug' => 'mantequilla', 'name' => 'Mantequilla', 'unit' => 'unidad', 'calories_kcal' => 1400, 'protein_g' => 1, 'carbs_g' => 1, 'fat_g' => 160],
        ['slug' => 'pan', 'name' => 'Pan', 'unit' => 'unidad', 'calories_kcal' => 80, 'protein_g' => 3, 'carbs_g' => 14, 'fat_g' => 1],
        ['slug' => 'cebolla-roja', 'name' => 'Cebolla roja', 'unit' => 'kg', 'calories_kcal' => 400, 'protein_g' => 11, 'carbs_g' => 93, 'fat_g' => 1],
        ['slug' => 'tomate', 'name' => 'Tomate', 'unit' => 'kg', 'calories_kcal' => 180, 'protein_g' => 9, 'carbs_g' => 39, 'fat_g' => 2],
        ['slug' => 'ajo', 'name' => 'Ajo', 'unit' => 'kg', 'calories_kcal' => 1500, 'protein_g' => 60, 'carbs_g' => 330, 'fat_g' => 5],
        ['slug' => 'limon', 'name' => 'Limón', 'unit' => 'kg', 'calories_kcal' => 300, 'protein_g' => 7, 'carbs_g' => 90, 'fat_g' => 3],
        ['slug' => 'palta', 'name' => 'Palta', 'unit' => 'unidad', 'calories_kcal' => 240, 'protein_g' => 3, 'carbs_g' => 12, 'fat_g' => 22],
        ['slug' => 'platano', 'name' => 'Plátano', 'unit' => 'kg', 'calories_kcal' => 890, 'protein_g' => 11, 'carbs_g' => 228, 'fat_g' => 3],
        ['slug' => 'platano-de-freir', 'name' => 'Plátano de freír', 'unit' => 'kg', 'calories_kcal' => 1220, 'protein_g' => 13, 'carbs_g' => 320, 'fat_g' => 4],
        ['slug' => 'zanahoria', 'name' => 'Zanahoria', 'unit' => 'kg', 'calories_kcal' => 410, 'protein_g' => 9, 'carbs_g' => 96, 'fat_g' => 2],
        ['slug' => 'choclo', 'name' => 'Choclo', 'unit' => 'unidad', 'calories_kcal' => 90, 'protein_g' => 3, 'carbs_g' => 19, 'fat_g' => 1],
        ['slug' => 'culantro', 'name' => 'Culantro', 'unit' => 'atado', 'calories_kcal' => 20, 'protein_g' => 2, 'carbs_g' => 3, 'fat_g' => 0],
        ['slug' => 'perejil', 'name' => 'Perejil', 'unit' => 'atado', 'calories_kcal' => 20, 'protein_g' => 2, 'carbs_g' => 3, 'fat_g' => 0],
        ['slug' => 'apio', 'name' => 'Apio', 'unit' => 'atado', 'calories_kcal' => 30, 'protein_g' => 1, 'carbs_g' => 6, 'fat_g' => 0],
        ['slug' => 'rocoto', 'name' => 'Rocoto', 'unit' => 'kg', 'calories_kcal' => 300, 'protein_g' => 12, 'carbs_g' => 60, 'fat_g' => 3],
        ['slug' => 'aji-amarillo', 'name' => 'Ají amarillo', 'unit' => 'kg', 'calories_kcal' => 350, 'protein_g' => 12, 'carbs_g' => 70, 'fat_g' => 4],
        ['slug' => 'pescado-fresco', 'name' => 'Pescado fresco', 'unit' => 'kg', 'calories_kcal' => 1000, 'protein_g' => 200, 'carbs_g' => 0, 'fat_g' => 30],
        ['slug' => 'atun-en-lata', 'name' => 'Atún en lata', 'unit' => 'unidad', 'calories_kcal' => 120, 'protein_g' => 25, 'carbs_g' => 0, 'fat_g' => 2],
        ['slug' => 'cafe-molido', 'name' => 'Café molido', 'unit' => 'paquete', 'calories_kcal' => 500, 'protein_g' => 20, 'carbs_g' => 80, 'fat_g' => 30],
        ['slug' => 'avena', 'name' => 'Avena', 'unit' => 'paquete', 'calories_kcal' => 1900, 'protein_g' => 65, 'carbs_g' => 330, 'fat_g' => 35],
        ['slug' => 'quinua', 'name' => 'Quinua', 'unit' => 'kg', 'calories_kcal' => 3680, 'protein_g' => 140, 'carbs_g' => 640, 'fat_g' => 60],
        ['slug' => 'manzana', 'name' => 'Manzana', 'unit' => 'kg', 'calories_kcal' => 520, 'protein_g' => 3, 'carbs_g' => 140, 'fat_g' => 2],
        ['slug' => 'naranja', 'name' => 'Naranja', 'unit' => 'kg', 'calories_kcal' => 470, 'protein_g' => 9, 'carbs_g' => 117, 'fat_g' => 1],
        ['slug' => 'siete-semillas', 'name' => 'Siete semillas molida', 'unit' => 'kg', 'calories_kcal' => 4500, 'protein_g' => 200, 'carbs_g' => 400, 'fat_g' => 300],
        ['slug' => 'soya', 'name' => 'Soya molida', 'unit' => 'kg', 'calories_kcal' => 3500, 'protein_g' => 350, 'carbs_g' => 300, 'fat_g' => 80],
    ],

];
