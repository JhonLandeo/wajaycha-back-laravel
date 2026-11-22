<?php

declare(strict_types=1);

namespace App\Services\Shopping;

use App\DTOs\Shopping\ConsumptionHabitLine;
use App\Enums\MealEntryKind;
use App\Enums\MealEntryStatus;
use App\Enums\Unit;
use App\Models\Recipe;
use Carbon\CarbonImmutable;

/**
 * Translates a saved menu into the same plain need lines the habits produce.
 *
 * Shared by the menu-only list and the unified list, so the recipe scaling and
 * the leftover rule live in one place: a cooked meal already left the pantry
 * through `pantry_consumptions` and must not buy its ingredients twice
 * (ADR-0020). The service reads values from the entries it is given; it opens
 * no connection and runs no query.
 */
final class MenuNeedsBuilder
{
    /**
     * @param  iterable<int, \App\Models\MealPlanEntry>  $entries
     * @param  array<int, int>  $planWeekdays
     * @param  array<int, string>  $mealTypes
     * @param  CarbonImmutable|null  $notBefore  Ignore planned entries from earlier
     *                                          days; null keeps a historical week.
     * @return array<int, ConsumptionHabitLine>
     */
    public function build(
        iterable $entries,
        array $planWeekdays,
        array $mealTypes,
        ?CarbonImmutable $notBefore = null,
    ): array {
        $aggregated = [];

        foreach ($entries as $entry) {
            if ($notBefore !== null && $entry->plan_date->toDateString() < $notBefore->toDateString()) {
                continue;
            }
            if (! in_array($entry->plan_date->isoWeekday(), $planWeekdays, true)) {
                continue;
            }
            if (! in_array($entry->meal_type->value, $mealTypes, true)) {
                continue;
            }
            if ($entry->kind !== MealEntryKind::Recipe || $entry->recipe === null) {
                continue;
            }
            if ($entry->status !== MealEntryStatus::Planned) {
                continue;
            }

            $this->aggregateRecipe($aggregated, $entry->recipe, (int) $entry->cook_servings);

            // El acompañamiento se prepara para lo que se come, no para las
            // porciones extra que quedan como sobras.
            if ($entry->drinkRecipe !== null) {
                $this->aggregateRecipe($aggregated, $entry->drinkRecipe, (int) $entry->servings);
            }
        }

        $lines = [];
        foreach ($aggregated as $productId => $need) {
            $lines[] = new ConsumptionHabitLine(
                productId: $productId,
                productName: $need['name'],
                unit: $need['unit'],
                weeklyQuantity: round($need['quantity'], 3),
            );
        }

        usort($lines, static fn (ConsumptionHabitLine $a, ConsumptionHabitLine $b): int => $a->productName <=> $b->productName);

        return $lines;
    }

    /**
     * @param  array<int, array{name: string, unit: Unit, quantity: float}>  $aggregated
     */
    private function aggregateRecipe(array &$aggregated, Recipe $recipe, int $servings): void
    {
        $scale = max(1, $servings) / max(1, (int) $recipe->base_servings);

        foreach ($recipe->ingredients as $ingredient) {
            if ($ingredient->is_optional) {
                continue;
            }

            $product = $ingredient->product;
            $productUnit = Unit::tryFrom((string) $product->unit);
            $ingredientUnit = Unit::tryFrom((string) $ingredient->unit);

            if ($productUnit === null || $ingredientUnit === null) {
                continue;
            }

            try {
                $quantity = UnitConverter::toCanonical(
                    (float) $ingredient->quantity * $scale,
                    $ingredientUnit->value,
                    $productUnit->value
                );
            } catch (\InvalidArgumentException) {
                continue;
            }

            $productId = (int) $product->id;
            $aggregated[$productId] ??= [
                'name' => $product->name,
                'unit' => $productUnit,
                'quantity' => 0.0,
            ];
            $aggregated[$productId]['quantity'] += $quantity;
        }
    }
}
