<?php

declare(strict_types=1);

namespace App\Services\Shopping;

use App\DTOs\Shopping\Nutrition;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use InvalidArgumentException;

/**
 * Calcula la nutrición aproximada de una receta a partir de sus productos.
 *
 * El producto guarda sus valores por unidad canónica (ADR-0031); la receta
 * puede declarar gramos sobre un producto en kilos, así que primero se convierte
 * con {@see UnitConverter}. Una receta se escala por `base_servings`; una
 * entrada del menú, por las porciones que se comen. Las sobras se calculan con
 * las porciones de la entrada de sobras, no con `cook_servings`: lo que sobra no
 * se come en esa comida.
 */
final class NutritionCalculator
{
    public function perServing(Recipe $recipe): Nutrition
    {
        $recipe->loadMissing('ingredients.product');

        return $this->forBaseRecipe($recipe)->scale(1.0 / max(1, (int) $recipe->base_servings));
    }

    public function forRecipe(Recipe $recipe, int $servings): Nutrition
    {
        return $this->perServing($recipe)->scale(max(0, $servings));
    }

    public function forEntry(MealPlanEntry $entry): Nutrition
    {
        if ($entry->recipe === null) {
            return Nutrition::zero();
        }

        $nutrition = $this->forRecipe($entry->recipe, (int) $entry->servings);

        if ($entry->drinkRecipe !== null) {
            $nutrition = $nutrition->add($this->forRecipe($entry->drinkRecipe, (int) $entry->servings));
        }

        return $nutrition;
    }

    private function forBaseRecipe(Recipe $recipe): Nutrition
    {
        $kcal = 0.0;
        $protein = 0.0;
        $carbs = 0.0;
        $fat = 0.0;
        $missing = [];

        foreach ($recipe->ingredients as $ingredient) {
            $product = $ingredient->product;

            $values = [
                $product->calories_kcal,
                $product->protein_g,
                $product->carbs_g,
                $product->fat_g,
            ];

            if ($values === [null, null, null, null]) {
                $missing[] = (string) $product->name;

                continue;
            }

            try {
                $quantity = UnitConverter::toCanonical(
                    (float) $ingredient->quantity,
                    (string) $ingredient->unit,
                    (string) $product->unit,
                );
            } catch (InvalidArgumentException) {
                $missing[] = (string) $product->name;

                continue;
            }

            $kcal += ($product->calories_kcal ?? 0.0) * $quantity;
            $protein += ($product->protein_g ?? 0.0) * $quantity;
            $carbs += ($product->carbs_g ?? 0.0) * $quantity;
            $fat += ($product->fat_g ?? 0.0) * $quantity;

            if (in_array(null, $values, true)) {
                $missing[] = (string) $product->name;
            }
        }

        return new Nutrition($kcal, $protein, $carbs, $fat, array_values(array_unique($missing)));
    }
}
