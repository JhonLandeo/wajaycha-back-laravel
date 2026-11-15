<?php

declare(strict_types=1);

namespace App\Services\Shopping;

use App\Enums\MealEntryKind;
use App\Models\MealPlanSettings;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Collection;

/**
 * Valida las operaciones que devuelve el chat contra el estado real de la
 * semana (ADR-0020, decisión 6).
 *
 * La IA propone; este validador descarta cualquier operación que mencione una
 * fecha inactiva, una comida no configurada, un `recipe_id` fuera del catálogo
 * filtrado, una porción imposible o una entrada inexistente. El resultado es el
 * único conjunto de operaciones que el backend acepta aplicar.
 */
final class MenuOperationValidator
{
    private const MAX_OPERATIONS = 21;

    /**
     * @param  array<int, mixed>  $raw
     * @param  array<int, string>  $days
     * @param  array<int, string>  $meals
     * @param  array<string, array<int, Recipe>>  $candidates
     * @param  Collection<int, \App\Models\MealPlanEntry>  $entries
     * @return array<int, array<string, mixed>>
     */
    public function validate(
        array $raw,
        array $days,
        array $meals,
        array $candidates,
        Collection $entries,
        MealPlanSettings $settings,
    ): array {
        $allowed = [];
        foreach ($candidates as $meal => $recipes) {
            $allowed[$meal] = array_fill_keys(
                array_map(static fn (Recipe $recipe): int => (int) $recipe->id, $recipes),
                true,
            );
        }

        $bySlot = [];
        foreach ($entries as $entry) {
            $bySlot[$entry->plan_date->toDateString().'|'.$entry->meal_type->value] = $entry;
        }

        $household = (int) $settings->household_size;
        $operations = [];

        foreach ($raw as $operation) {
            if (count($operations) >= self::MAX_OPERATIONS) {
                break;
            }

            if (! is_array($operation) || ! isset($operation['op']) || ! is_string($operation['op'])) {
                continue;
            }

            $date = (string) ($operation['date'] ?? '');

            if (! in_array($date, $days, true)) {
                continue;
            }

            $name = $operation['op'];

            if ($name === 'clear_day' || $name === 'regenerate_day') {
                $operations[] = ['op' => $name, 'date' => $date];

                continue;
            }

            $meal = (string) ($operation['meal'] ?? '');

            if (! in_array($meal, $meals, true)) {
                continue;
            }

            switch ($name) {
                case 'swap_recipe':
                    $recipeId = (int) ($operation['recipe_id'] ?? 0);

                    if (! isset($allowed[$meal][$recipeId])) {
                        continue 2;
                    }

                    $operations[] = [
                        'op' => 'swap_recipe',
                        'date' => $date,
                        'meal' => $meal,
                        'recipe_id' => $recipeId,
                    ];
                    break;

                case 'set_cook_servings':
                    $entry = $bySlot[$date.'|'.$meal] ?? null;
                    $cookServings = (int) ($operation['cook_servings'] ?? 0);

                    if ($entry === null
                        || $entry->kind !== MealEntryKind::Recipe
                        || $cookServings < $household
                        || $cookServings > $household * 2) {
                        continue 2;
                    }

                    $operations[] = [
                        'op' => 'set_cook_servings',
                        'date' => $date,
                        'meal' => $meal,
                        'cook_servings' => $cookServings,
                    ];
                    break;

                case 'mark_eat_out':
                    $operations[] = ['op' => 'mark_eat_out', 'date' => $date, 'meal' => $meal];
                    break;
            }
        }

        return $operations;
    }
}
