<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Database\Seeder;

/**
 * Siembra las recetas globales del catálogo y sus ingredientes.
 *
 * Es idempotente por `slug`: actualiza la receta existente y reemplaza sus
 * ingredientes. Los productos se resuelven por slug, nunca por id, porque el
 * id depende del orden en que se sembró el catálogo.
 *
 * Se ejecuta después de `ProductCatalogueSeeder`; una receta que referencia un
 * slug inexistente se salta para no romper el deploy completo.
 */
class RecipeCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<string, string> $images */
        $images = config('recipes.images', []);

        $products = Product::query()
            ->whereNull('user_id')
            ->whereNotNull('slug')
            ->get()
            ->keyBy('slug');

        foreach (config('recipes.catalogue', []) as $data) {
            $ingredients = [];

            foreach ($data['ingredients'] as $ingredient) {
                $product = $products->get($ingredient['product']);
                if ($product === null) {
                    continue 2;
                }

                $ingredients[] = [
                    'product_id' => (int) $product->id,
                    'quantity' => $ingredient['quantity'],
                    'unit' => $ingredient['unit'],
                    'is_optional' => (bool) ($ingredient['is_optional'] ?? false),
                ];
            }

            $recipe = Recipe::query()->updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'user_id' => null,
                    'name' => $data['name'],
                    'base_servings' => $data['base_servings'],
                    'meal_types' => $data['meal_types'],
                    'course' => $data['course'] ?? 'main',
                    'origin' => 'catalog',
                    'cuisine' => $data['cuisine'] ?? 'peruvian',
                    'required_equipment' => $data['required_equipment'] ?? [],
                    'diet_tags' => $data['diet_tags'] ?? [],
                    'prep_minutes' => $data['prep_minutes'],
                    'instructions' => null,
                    'image_url' => $images[$this->imageKey($data)] ?? null,
                    'is_active' => true,
                ]
            );

            $recipe->ingredients()->delete();

            foreach ($ingredients as $ingredient) {
                RecipeIngredient::query()->create([
                    'recipe_id' => $recipe->id,
                    ...$ingredient,
                ]);
            }
        }
    }

    /**
     * El catálogo no guarda una foto por receta: usa una portada acorde al tipo
     * de plato. La URL se resuelve al sembrar y queda guardada en la fila.
     *
     * @param  array<string, mixed>  $data
     */
    private function imageKey(array $data): string
    {
        $slug = (string) ($data['slug'] ?? '');

        if (($data['course'] ?? 'main') === 'drink') {
            return str_contains($slug, 'leche') ? 'milk' : 'drink';
        }

        $rules = [
            'ensalada' => 'salad',
            'sopa|aguadito|chupe|caldo' => 'soup',
            'tallarin|mondonguito|fideo|pasta' => 'pasta',
            'pescado|atun|marisco|chicharron-de-pescado|escabeche' => 'fish',
            'arroz-con-leche|postre|mazamorra' => 'dessert',
            'pollo|gallina' => 'chicken',
            'res|lomo|seco|anticucho|carapulcra|cau-cau|tacu-tacu' => 'beef',
            'huevo|tortilla|tostada|pan-|sandwich|avena|yogur|pancake|panqueque' => 'breakfast',
            'arroz|quinua|chaufa' => 'rice',
        ];

        foreach ($rules as $pattern => $key) {
            if (preg_match('/'.$pattern.'/', $slug) === 1) {
                return $key;
            }
        }

        return 'food';
    }
}
