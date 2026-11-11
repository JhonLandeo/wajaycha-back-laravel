<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\DTOs\Shopping\PlanningWeek;
use App\Models\MealPlanSettings;
use App\Models\PantryItem;
use App\Models\Recipe;
use App\Repositories\Contracts\FoodBudgetRepositoryContract;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use App\Repositories\Contracts\RecipeRepositoryContract;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use App\Services\AI\OpenCodeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Propone una semana completa y la guarda como entradas planificadas.
 *
 * La IA elige ids del catálogo ya filtrado; el backend valida cada id, la
 * comida y las porciones. Si la IA falla o devuelve algo inválido, la semana se
 * completa con una elección determinística que prioriza despensa y variedad.
 * Nunca se inventan recetas ni ingredientes.
 */
final class SuggestMenuAction
{
    public function __construct(
        private readonly MealPlanRepositoryContract $mealPlanRepository,
        private readonly RecipeRepositoryContract $recipeRepository,
        private readonly ShoppingRepositoryContract $shoppingRepository,
        private readonly FoodBudgetRepositoryContract $foodBudgetRepository,
        private readonly OpenCodeGateway $gateway,
    ) {}

    /**
     * @return 'ai'|'fallback'
     */
    public function execute(int $userId, string $weekStart): string
    {
        $asOf = CarbonImmutable::now();
        $week = PlanningWeek::forWeekStarting(CarbonImmutable::parse($weekStart, 'America/Lima'), $asOf);
        $settings = $this->mealPlanRepository->settingsFor($userId);
        $days = $this->activeDays($week, $settings->plan_weekdays);
        $meals = $settings->meal_types;
        $candidates = $this->candidates($userId, $settings);
        $pantry = $this->shoppingRepository->listPantryItemsFor($userId);

        $selected = $this->fromAi($week, $settings, $days, $meals, $candidates, $pantry);
        $source = $selected === [] ? 'fallback' : 'ai';

        DB::transaction(function () use ($userId, $week, $settings, $days, $meals, $candidates, $pantry, $selected): void {
            $this->mealPlanRepository->clearWeek($userId, $week->startsOn, $week->endsOn);

            $created = [];
            foreach ($selected as $key => $choice) {
                [$date, $meal] = explode('|', $key);
                $entry = $this->mealPlanRepository->upsertEntry($userId, [
                    'plan_date' => $date,
                    'meal_type' => $meal,
                    'kind' => 'recipe',
                    'recipe_id' => $choice['recipe_id'],
                    'servings' => (int) $settings->household_size,
                    'cook_servings' => $choice['cook_servings'],
                    'leftover_of_entry_id' => null,
                    'notes' => null,
                ]);
                $created[$key] = $entry;
            }

            // Antes de rellenar, convertir las porciones extra de la IA en sobras.
            // Si se rellenara primero, cada día tendría una receta y ninguna sobra
            // encontraría un hueco.
            $created = $this->scheduleLeftovers($userId, $settings, $days, $meals, $created);

            foreach ($days as $date) {
                foreach ($meals as $meal) {
                    $key = $date.'|'.$meal;
                    if (isset($created[$key])) {
                        continue;
                    }

                    $choice = $this->fallbackChoice($candidates[$meal] ?? [], $pantry, $selected);
                    if ($choice === null) {
                        continue;
                    }

                    $selected[$key] = [
                        'recipe_id' => (int) $choice->id,
                        'cook_servings' => (int) $settings->household_size,
                    ];

                    $created[$key] = $this->mealPlanRepository->upsertEntry($userId, [
                        'plan_date' => $date,
                        'meal_type' => $meal,
                        'kind' => 'recipe',
                        'recipe_id' => (int) $choice->id,
                        'servings' => (int) $settings->household_size,
                        'cook_servings' => (int) $settings->household_size,
                        'leftover_of_entry_id' => null,
                        'notes' => null,
                    ]);
                }
            }
        });

        return $source;
    }

    /**
     * @param  array<int, int>  $planWeekdays
     * @return array<int, string>
     */
    private function activeDays(PlanningWeek $week, array $planWeekdays): array
    {
        $days = [];

        for ($offset = 0; $offset < 7; $offset++) {
            $date = $week->startsOn->addDays($offset);
            if (in_array($date->isoWeekday(), $planWeekdays, true)) {
                $days[] = $date->toDateString();
            }
        }

        return $days;
    }

    /**
     * @return array<string, array<int, Recipe>>
     */
    private function candidates(int $userId, MealPlanSettings $settings): array
    {
        $recipes = $this->recipeRepository->allForUser($userId);
        $excluded = array_map(fn (string $term): string => $this->fold($term), $settings->excluded_terms ?? []);
        $byMeal = [];

        foreach ($recipes as $recipe) {
            if ($settings->max_prep_minutes !== null
                && $recipe->prep_minutes !== null
                && $recipe->prep_minutes > $settings->max_prep_minutes) {
                continue;
            }

            $haystack = $this->fold($recipe->name.' '.$recipe->ingredients
                ->map(fn ($ingredient): string => (string) $ingredient->product->name)
                ->implode(' '));

            if ($excluded !== [] && array_filter($excluded, fn (string $term): bool => $term !== '' && str_contains($haystack, $term)) !== []) {
                continue;
            }

            foreach ($recipe->meal_types as $meal) {
                if (in_array($meal, $settings->meal_types, true)) {
                    $byMeal[$meal][] = $recipe;
                }
            }
        }

        return $byMeal;
    }

    /**
     * @param  array<int, string>  $days
     * @param  array<int, string>  $meals
     * @param  array<string, array<int, Recipe>>  $candidates
     * @param  array<int, PantryItem>  $pantry
     * @return array<string, array{recipe_id: int, cook_servings: int}>
     */
    private function fromAi(
        PlanningWeek $week,
        MealPlanSettings $settings,
        array $days,
        array $meals,
        array $candidates,
        array $pantry,
    ): array {
        try {
            $response = $this->gateway->generateJson([
                ['parts' => [['text' => $this->prompt($week, $settings, $days, $meals, $candidates, $pantry)]]],
            ], ['temperature' => 0.4]);
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        if ($response === null || ! isset($response['days']) || ! is_array($response['days'])) {
            return [];
        }

        $allowed = [];
        foreach ($candidates as $meal => $recipes) {
            $allowed[$meal] = array_fill_keys(array_map(static fn (Recipe $recipe): int => (int) $recipe->id, $recipes), true);
        }

        $selected = [];
        foreach ($response['days'] as $day) {
            if (! is_array($day) || ! isset($day['date'], $day['meals']) || ! is_array($day['meals'])) {
                continue;
            }

            $date = (string) $day['date'];
            if (! in_array($date, $days, true)) {
                continue;
            }

            foreach ($meals as $meal) {
                $choice = $day['meals'][$meal] ?? null;
                if (! is_array($choice)) {
                    continue;
                }

                $recipeId = (int) ($choice['recipe_id'] ?? 0);
                if (! isset($allowed[$meal][$recipeId])) {
                    continue;
                }

                $cook = (int) ($choice['cook_servings'] ?? $settings->household_size);
                $cook = max((int) $settings->household_size, min($cook, (int) $settings->household_size * 2));

                $selected[$date.'|'.$meal] = [
                    'recipe_id' => $recipeId,
                    'cook_servings' => $cook,
                ];
            }
        }

        return $selected;
    }

    /**
     * @param  array<int, string>  $days
     * @param  array<int, string>  $meals
     * @param  array<string, array<int, Recipe>>  $candidates
     * @param  array<int, PantryItem>  $pantry
     */
    private function prompt(
        PlanningWeek $week,
        MealPlanSettings $settings,
        array $days,
        array $meals,
        array $candidates,
        array $pantry,
    ): string {
        $budget = $this->foodBudgetRepository->snapshotFor($settings->user_id, CarbonImmutable::now());

        $recipes = [];
        foreach ($candidates as $meal => $items) {
            foreach ($items as $recipe) {
                $recipes[] = [
                    'id' => (int) $recipe->id,
                    'nombre' => $recipe->name,
                    'comidas' => $recipe->meal_types,
                    'minutos' => $recipe->prep_minutes,
                    'ingredientes' => $recipe->ingredients
                        ->map(static fn ($ingredient): string => (string) $ingredient->product->name)
                        ->filter()
                        ->values()
                        ->all(),
                ];
            }
        }

        $context = [
            'semana' => ['inicio' => $week->startsOn->toDateString(), 'fin' => $week->endsOn->toDateString()],
            'personas' => (int) $settings->household_size,
            'comidas_activas' => $meals,
            'dias_activos' => $days,
            'presupuesto_semanal' => $budget?->weeklyTarget,
            'despensa' => array_map(static fn (PantryItem $item): array => [
                'producto' => (string) $item->product->getAttribute('name'),
                'cantidad' => (float) $item->quantity,
                'unidad' => $item->unit,
                'vence' => $item->expires_on?->toDateString(),
            ], $pantry),
            'recetas' => $recipes,
        ];

        return <<<PROMPT
Eres un planificador de menú semanal para una familia peruana. Elige recetas del catálogo entregado y devuelve solo JSON válido.

Reglas:
- Usa únicamente recipe_id que aparezcan en el catálogo. No inventes recetas, ingredientes ni ids.
- Elige solo comidas compatibles con cada receta.
- Para cada día activo y cada comida activa, elige una receta o deja la comida vacía si no hay candidata.
- servings siempre es el número de personas. cook_servings debe ser >= personas; usa hasta 2x personas si quieres dejar sobras para el día siguiente.
- Prioriza despensa, especialmente lo que vence pronto; evita repetir el mismo plato demasiado; respeta el tiempo máximo y el presupuesto semanal si está disponible.
- No expliques nada fuera del JSON.

Contexto:
```json
{$this->json($context)}
```

Formato exacto:
{"days":[{"date":"YYYY-MM-DD","meals":{"breakfast":{"recipe_id":1,"cook_servings":2},"lunch":{"recipe_id":2,"cook_servings":4}}}]}
PROMPT;
    }

    /**
     * @param  array<int, Recipe>  $candidates
     * @param  array<int, PantryItem>  $pantry
     * @param  array<string, array{recipe_id: int, cook_servings: int}>  $selected
     */
    private function fallbackChoice(array $candidates, array $pantry, array $selected): ?Recipe
    {
        if ($candidates === []) {
            return null;
        }

        $pantryById = [];
        foreach ($pantry as $item) {
            $pantryById[(int) $item->product_id] = $item;
        }

        $usage = [];
        foreach ($selected as $choice) {
            $usage[$choice['recipe_id']] = ($usage[$choice['recipe_id']] ?? 0) + 1;
        }

        $scored = array_map(function (Recipe $recipe) use ($pantryById, $usage): array {
            $score = -($usage[(int) $recipe->id] ?? 0) * 5;
            foreach ($recipe->ingredients as $ingredient) {
                $stock = $pantryById[(int) $ingredient->product_id] ?? null;
                if ($stock === null) {
                    continue;
                }
                $score += 2;
                if ($stock->expires_on !== null && $stock->expires_on->lte(CarbonImmutable::now()->addDays(4))) {
                    $score += 2;
                }
            }

            return ['recipe' => $recipe, 'score' => $score];
        }, $candidates);

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['recipe']->name <=> $b['recipe']->name);

        return $scored[0]['recipe'];
    }

    /**
     * @param  array<int, string>  $days
     * @param  array<int, string>  $meals
     * @param  array<string, \App\Models\MealPlanEntry>  $created
     * @return array<string, \App\Models\MealPlanEntry>
     */
    private function scheduleLeftovers(
        int $userId,
        MealPlanSettings $settings,
        array $days,
        array $meals,
        array $created,
    ): array {
        foreach ($created as $key => $entry) {
            $extra = $entry->cook_servings - $entry->servings;
            if ($extra < 1) {
                continue;
            }

            [$date, $meal] = explode('|', $key);
            $nextDate = CarbonImmutable::parse($date, 'America/Lima')->addDay()->toDateString();

            if (! in_array($nextDate, $days, true) || ! in_array($meal, $meals, true) || isset($created[$nextDate.'|'.$meal])) {
                continue;
            }

            $leftover = $this->mealPlanRepository->upsertEntry($userId, [
                'plan_date' => $nextDate,
                'meal_type' => $meal,
                'kind' => 'leftover',
                'recipe_id' => $entry->recipe_id,
                'servings' => min($extra, (int) $settings->household_size),
                'cook_servings' => 0,
                'leftover_of_entry_id' => $entry->id,
                'notes' => null,
            ]);

            $created[$nextDate.'|'.$meal] = $leftover;
        }

        return $created;
    }

    private function fold(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
