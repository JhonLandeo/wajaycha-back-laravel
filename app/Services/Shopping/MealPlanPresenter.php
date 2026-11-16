<?php

declare(strict_types=1);

namespace App\Services\Shopping;

use App\DTOs\Shopping\PlanningWeek;
use App\Models\MealPlanEntry;
use App\Models\MealPlanSettings;
use App\Models\Recipe;

/**
 * Arma la representación JSON del menú.
 *
 * El controlador no decide la forma del payload; esta clase la centraliza para
 * que el preview, la alternativa por comida y el chat del preview devuelvan
 * exactamente el mismo contrato.
 */
final class MealPlanPresenter
{
    public function __construct(
        private readonly NutritionCalculator $nutrition,
    ) {}

    /**
     * @return array{starts_on: string, ends_on: string}
     */
    public function week(PlanningWeek $week): array
    {
        return [
            'starts_on' => $week->startsOn->toDateString(),
            'ends_on' => $week->endsOn->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(MealPlanSettings $settings): array
    {
        return [
            'household_size' => (int) $settings->household_size,
            'meal_types' => $settings->meal_types,
            'plan_weekdays' => $settings->plan_weekdays,
            'excluded_terms' => $settings->excluded_terms ?? [],
            'max_prep_minutes' => $settings->max_prep_minutes,
            'menu_strategy' => $settings->menu_strategy->value,
            'menu_strategy_label' => $settings->menu_strategy->label(),
            'available_equipment' => $settings->available_equipment,
            'diet_profile' => $settings->diet_profile->value,
            'diet_profile_label' => $settings->diet_profile->label(),
            'new_recipes_per_week' => (int) $settings->new_recipes_per_week,
            'daily_calorie_target' => $settings->daily_calorie_target,
            'dinner_carb_limit_g' => $settings->dinner_carb_limit_g,
            'dinner_max_prep_minutes' => $settings->dinner_max_prep_minutes,
            'dinner_calorie_limit' => $settings->dinner_calorie_limit,
            'consider_pantry_in_ai_recipes' => (bool) $settings->consider_pantry_in_ai_recipes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recipe(Recipe $recipe): array
    {
        return [
            'id' => (int) $recipe->id,
            'name' => $recipe->name,
            'base_servings' => (int) $recipe->base_servings,
            'prep_minutes' => $recipe->prep_minutes,
            'meal_types' => $recipe->meal_types,
            'course' => $recipe->course->value,
            'course_label' => $recipe->course->label(),
            'origin' => $recipe->origin->value,
            'origin_label' => $recipe->origin->label(),
            'cuisine' => $recipe->cuisine->value,
            'cuisine_label' => $recipe->cuisine->label(),
            'required_equipment' => $recipe->required_equipment ?? [],
            'diet_tags' => $recipe->diet_tags ?? [],
            'instructions' => $recipe->instructions,
            'nutrition' => $this->nutrition->perServing($recipe)->toArray(),
            'ingredients' => $recipe->ingredients
                ->map(static fn ($ingredient): array => [
                    'product_id' => (int) $ingredient->product_id,
                    'product_name' => (string) $ingredient->product->name,
                    'quantity' => (float) $ingredient->quantity,
                    'unit' => (string) $ingredient->unit,
                    'is_optional' => (bool) $ingredient->is_optional,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function entry(MealPlanEntry $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'plan_date' => $entry->plan_date->toDateString(),
            'meal_type' => $entry->meal_type->value,
            'meal_type_label' => $entry->meal_type->label(),
            'kind' => $entry->kind->value,
            'kind_label' => $entry->kind->label(),
            'status' => $entry->status->value,
            'status_label' => $entry->status->label(),
            'recipe_id' => $entry->recipe_id,
            'recipe' => $entry->recipe === null ? null : $this->recipe($entry->recipe),
            'drink_recipe_id' => $entry->drink_recipe_id,
            'drink_recipe' => $entry->drinkRecipe === null ? null : $this->recipe($entry->drinkRecipe),
            'servings' => (int) $entry->servings,
            'cook_servings' => (int) $entry->cook_servings,
            'leftover_of_entry_id' => $entry->leftover_of_entry_id,
            'leftover_from' => $entry->leftoverOf === null ? null : [
                'id' => (int) $entry->leftoverOf->id,
                'plan_date' => $entry->leftoverOf->plan_date->toDateString(),
                'meal_type' => $entry->leftoverOf->meal_type->value,
                'recipe_name' => $entry->leftoverOf->recipe?->name,
            ],
            'nutrition' => $this->nutrition->forEntry($entry)->toArray(),
            'notes' => $entry->notes,
        ];
    }
}
