<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\DTOs\Shopping\PlanningWeek;
use App\Enums\MealEntryKind;
use App\Enums\MealType;
use App\Models\MealPlanSettings;
use App\Models\Recipe;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use App\Services\Shopping\MenuCalendar;
use App\Services\Shopping\MenuCandidateFilter;
use App\Services\Shopping\MenuOperationValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Aplica las operaciones que el usuario confirmó desde el chat (ADR-0020).
 *
 * Vuelve a validarlas contra el estado actual —nunca confía en el cliente— y
 * usa `SaveMealPlanEntryAction`, la misma puerta que el resto del menú, para
 * que las sobras, las porciones y el consumo de despensa sigan siendo reglas
 * del backend.
 */
final class ApplyMenuChatOperationsAction
{
    public function __construct(
        private readonly MealPlanRepositoryContract $mealPlanRepository,
        private readonly SaveMealPlanEntryAction $saveEntry,
        private readonly MenuCandidateFilter $candidateFilter,
        private readonly MenuCalendar $calendar,
        private readonly MenuOperationValidator $validator,
    ) {}

    /**
     * @param  array<int, mixed>  $rawOperations
     * @return array<int, array<string, mixed>>
     */
    public function execute(int $userId, string $weekStart, array $rawOperations): array
    {
        $week = PlanningWeek::forWeekStarting(
            CarbonImmutable::parse($weekStart, 'America/Lima'),
            CarbonImmutable::now(),
        );
        $settings = $this->mealPlanRepository->settingsFor($userId);
        $days = $this->calendar->activeDays($week, $settings->plan_weekdays);
        $meals = $settings->meal_types;
        $candidates = $this->candidateFilter->forUser($userId, $settings);
        $entries = $this->mealPlanRepository->entriesForWeek($userId, $week->startsOn, $week->endsOn);

        $operations = $this->validator->validate(
            $rawOperations,
            $days,
            $meals,
            $candidates,
            $entries,
            $settings,
        );

        DB::transaction(function () use ($userId, $operations, $meals, $settings, $candidates): void {
            foreach ($operations as $operation) {
                $this->apply($userId, $operation, $meals, $settings, $candidates);
            }
        });

        return $operations;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<int, string>  $meals
     * @param  array<string, array<int, Recipe>>  $candidates
     */
    private function apply(int $userId, array $operation, array $meals, MealPlanSettings $settings, array $candidates): void
    {
        $date = (string) $operation['date'];
        $meal = isset($operation['meal']) ? (string) $operation['meal'] : null;
        $household = (int) $settings->household_size;

        match ($operation['op']) {
            'swap_recipe' => $this->saveEntry->execute($userId, [
                'plan_date' => $date,
                'meal_type' => (string) $meal,
                'kind' => MealEntryKind::Recipe->value,
                'recipe_id' => (int) $operation['recipe_id'],
                'servings' => $household,
                'cook_servings' => $household,
            ]),
            'set_cook_servings' => $this->updateCookServings($userId, $date, (string) $meal, (int) $operation['cook_servings']),
            'mark_eat_out' => $this->saveEntry->execute($userId, [
                'plan_date' => $date,
                'meal_type' => (string) $meal,
                'kind' => MealEntryKind::EatOut->value,
                'servings' => 0,
                'cook_servings' => 0,
            ]),
            'clear_day' => $this->clearDay($userId, $date, $meals),
            'regenerate_day' => $this->regenerateDay($userId, $date, $meals, $household, $candidates),
            default => null,
        };
    }

    private function updateCookServings(int $userId, string $date, string $meal, int $cookServings): void
    {
        $entry = $this->mealPlanRepository->findSlot($userId, $date, MealType::from($meal));

        if ($entry === null || $entry->kind !== MealEntryKind::Recipe || $entry->recipe_id === null) {
            return;
        }

        $this->saveEntry->execute($userId, [
            'plan_date' => $date,
            'meal_type' => $meal,
            'kind' => MealEntryKind::Recipe->value,
            'recipe_id' => (int) $entry->recipe_id,
            'servings' => (int) $entry->servings,
            'cook_servings' => $cookServings,
        ]);
    }

    /**
     * @param  array<int, string>  $meals
     */
    private function clearDay(int $userId, string $date, array $meals): void
    {
        $day = CarbonImmutable::parse($date, 'America/Lima');
        $entries = $this->mealPlanRepository->entriesForWeek($userId, $day, $day);

        foreach ($entries as $entry) {
            if (in_array($entry->meal_type->value, $meals, true)) {
                $this->mealPlanRepository->deleteEntry($entry);
            }
        }
    }

    /**
     * Reemplaza cada comida activa del día por la receta menos usada de la
     * semana, para que regenerar no devuelva el mismo plato siete veces.
     *
     * @param  array<int, string>  $meals
     * @param  array<string, array<int, Recipe>>  $candidates
     */
    private function regenerateDay(int $userId, string $date, array $meals, int $household, array $candidates): void
    {
        $week = PlanningWeek::forWeekStarting(
            CarbonImmutable::parse($date, 'America/Lima'),
            CarbonImmutable::now(),
        );
        $entries = $this->mealPlanRepository->entriesForWeek($userId, $week->startsOn, $week->endsOn);
        $usage = [];

        foreach ($entries as $entry) {
            if ($entry->recipe_id !== null) {
                $usage[(int) $entry->recipe_id] = ($usage[(int) $entry->recipe_id] ?? 0) + 1;
            }
        }

        foreach ($meals as $meal) {
            $options = $candidates[$meal] ?? [];

            if ($options === []) {
                continue;
            }

            usort($options, static function (Recipe $a, Recipe $b) use ($usage): int {
                return (($usage[(int) $a->id] ?? 0) <=> ($usage[(int) $b->id] ?? 0))
                    ?: $a->name <=> $b->name;
            });

            $recipe = $options[0];
            $usage[(int) $recipe->id] = ($usage[(int) $recipe->id] ?? 0) + 1;

            $this->saveEntry->execute($userId, [
                'plan_date' => $date,
                'meal_type' => $meal,
                'kind' => MealEntryKind::Recipe->value,
                'recipe_id' => (int) $recipe->id,
                'servings' => $household,
                'cook_servings' => $household,
            ]);
        }
    }
}
