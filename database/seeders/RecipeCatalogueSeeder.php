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
                    'prep_minutes' => $data['prep_minutes'],
                    'instructions' => null,
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
}
