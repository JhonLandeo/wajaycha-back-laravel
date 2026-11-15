<?php

declare(strict_types=1);

namespace App\Services\Shopping;

use App\Enums\DietProfile;
use App\Enums\DietTag;
use App\Enums\KitchenEquipment;
use App\Enums\RecipeCourse;
use App\Enums\RecipeOrigin;
use App\Enums\Unit;
use App\Models\MealPlanSettings;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Valida una receta propuesta por la IA antes de guardarla o mostrarla.
 *
 * La IA solo puede elegir productos del catálogo; el backend comprueba ids,
 * unidades, cantidades, equipamiento, tiempo, dieta y términos excluidos. No
 * inventa productos ni precios. El resultado se puede guardar como receta
 * privada (`origin = ai`) o devolver como borrador editable.
 */
final class AiRecipeDraftValidator
{
    private const MAX_INGREDIENTS = 20;

    private const MAX_INSTRUCTIONS = 5000;

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<int, Product>  $productsById
     * @param  array<string, true>  $existingNames
     * @return array{
     *     data: array<string, mixed>,
     *     ingredients: array<int, array{product_id: int, quantity: float, unit: string, is_optional: bool}>
     * }|null
     */
    public function validate(
        array $raw,
        MealPlanSettings $settings,
        array $productsById,
        array &$existingNames,
    ): ?array {
        $name = trim((string) ($raw['name'] ?? ''));
        $folded = $this->fold($name);

        if ($name === '' || mb_strlen($name) > 120 || isset($existingNames[$folded])) {
            return null;
        }

        $mealTypes = array_values(array_intersect(
            $settings->meal_types,
            array_map('strval', (array) ($raw['meal_types'] ?? [])),
        ));

        if ($mealTypes === []) {
            return null;
        }

        $course = RecipeCourse::tryFrom((string) ($raw['course'] ?? 'main')) ?? RecipeCourse::Main;

        if (! in_array($course, [RecipeCourse::Main, RecipeCourse::Drink], true)) {
            return null;
        }

        $dietTags = array_values(array_unique(array_map('strval', (array) ($raw['diet_tags'] ?? []))));
        $dietTags = array_values(array_filter(
            $dietTags,
            static fn (string $value): bool => DietTag::tryFrom($value) !== null,
        ));

        $profile = $settings->diet_profile ?? DietProfile::Balanced;

        if ($profile === DietProfile::Vegetarian
            && ! in_array('vegetarian', $dietTags, true)
            && ! in_array('vegan', $dietTags, true)) {
            return null;
        }

        if ($profile === DietProfile::Vegan && ! in_array('vegan', $dietTags, true)) {
            return null;
        }

        $equipment = array_values(array_unique(array_map('strval', (array) ($raw['equipment'] ?? []))));
        $equipment = array_values(array_filter(
            $equipment,
            static fn (string $value): bool => KitchenEquipment::tryFrom($value) !== null,
        ));

        if ($settings->available_equipment !== null) {
            foreach ($equipment as $required) {
                if (! in_array($required, $settings->available_equipment, true)) {
                    return null;
                }
            }
        }

        $prepMinutes = $raw['prep_minutes'] ?? null;
        $prepMinutes = is_numeric($prepMinutes) ? (int) $prepMinutes : null;

        if ($prepMinutes !== null && $settings->max_prep_minutes !== null && $prepMinutes > $settings->max_prep_minutes) {
            return null;
        }

        $rawIngredients = $raw['ingredients'] ?? null;

        if (! is_array($rawIngredients) || $rawIngredients === [] || count($rawIngredients) > self::MAX_INGREDIENTS) {
            return null;
        }

        $ingredients = [];
        $byProduct = [];
        $haystackParts = [$name];

        foreach ($rawIngredients as $ingredient) {
            if (! is_array($ingredient)) {
                return null;
            }

            $productId = (int) ($ingredient['product_id'] ?? $ingredient['producto_id'] ?? 0);
            $product = $productsById[$productId] ?? null;

            if ($product === null) {
                return null;
            }

            $unit = Unit::tryFrom((string) ($ingredient['unit'] ?? ''));
            $productUnit = Unit::tryFrom((string) $product->unit);
            $quantity = (float) ($ingredient['quantity'] ?? 0);

            if ($unit === null || $productUnit === null || $quantity <= 0.0 || $quantity > 999999999.999) {
                return null;
            }

            if (! UnitConverter::compatible($unit->value, $productUnit->value)) {
                return null;
            }

            $canonical = UnitConverter::toCanonical($quantity, $unit->value, $productUnit->value);
            $byProduct[$productId] = round(($byProduct[$productId] ?? 0.0) + $canonical, 3);
            $haystackParts[] = (string) $product->name;
        }

        $haystack = $this->fold(implode(' ', $haystackParts));

        foreach ($settings->excluded_terms ?? [] as $term) {
            $term = $this->fold((string) $term);

            if ($term !== '' && str_contains($haystack, $term)) {
                return null;
            }
        }

        foreach ($byProduct as $productId => $quantity) {
            $productUnit = Unit::from((string) $productsById[$productId]->unit);

            $ingredients[] = [
                'product_id' => (int) $productId,
                'quantity' => $quantity,
                'unit' => $productUnit->value,
                'is_optional' => false,
            ];
        }

        $instructions = trim((string) ($raw['instructions'] ?? ''));

        if ($instructions === '') {
            $instructions = 'Propuesta por IA; revisa la preparación antes de cocinar.';
        }

        if (mb_strlen($instructions) > self::MAX_INSTRUCTIONS) {
            $instructions = mb_substr($instructions, 0, self::MAX_INSTRUCTIONS);
        }

        return [
            'data' => [
                'name' => $name,
                'base_servings' => (int) $settings->household_size,
                'meal_types' => $mealTypes,
                'course' => $course->value,
                'origin' => RecipeOrigin::Ai->value,
                'required_equipment' => $equipment,
                'diet_tags' => $dietTags,
                'prep_minutes' => $prepMinutes,
                'instructions' => $instructions,
                'is_active' => true,
            ],
            'ingredients' => $ingredients,
        ];
    }

    private function fold(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }
}
