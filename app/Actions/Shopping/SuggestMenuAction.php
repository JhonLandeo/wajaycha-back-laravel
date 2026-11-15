<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\DTOs\Shopping\PantryStock;
use App\DTOs\Shopping\PlanningWeek;
use App\Enums\MenuStrategy;
use App\Enums\Unit;
use App\Models\MealPlanSettings;
use App\Models\Recipe;
use App\Repositories\Contracts\FoodBudgetRepositoryContract;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use App\Services\AI\OpenCodeGateway;
use App\Services\Shopping\MenuCalendar;
use App\Services\Shopping\MenuCandidateFilter;
use App\Services\Shopping\UnitConverter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
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
        private readonly ShoppingRepositoryContract $shoppingRepository,
        private readonly FoodBudgetRepositoryContract $foodBudgetRepository,
        private readonly MenuCandidateFilter $candidateFilter,
        private readonly MenuCalendar $calendar,
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
        $days = $this->calendar->activeDays($week, $settings->plan_weekdays);
        $meals = $settings->meal_types;
        $candidates = $this->candidateFilter->forUser($userId, $settings);
        $pantry = $this->shoppingRepository->pantryStockFor($userId);

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

                    $choice = $this->fallbackChoice($candidates[$meal] ?? [], $pantry, $selected, $settings);
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
     * @param  array<int, string>  $days
     * @param  array<int, string>  $meals
     * @param  array<string, array<int, Recipe>>  $candidates
     * @param  array<int, PantryStock>  $pantry
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
     * @param  array<int, PantryStock>  $pantry
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
        $available = $this->pantryAvailabilityByProduct($pantry);

        $recipes = [];
        foreach ($candidates as $meal => $items) {
            foreach ($items as $recipe) {
                $coverage = $this->coverageFor($recipe, (int) $settings->household_size, $available);

                $recipes[] = [
                    'id' => (int) $recipe->id,
                    'nombre' => $recipe->name,
                    'comidas' => $recipe->meal_types,
                    'minutos' => $recipe->prep_minutes,
                    'despensa_cobertura' => $coverage['cobertura'],
                    'vence_pronto' => $coverage['vence_pronto'],
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
            'criterio' => ($settings->menu_strategy ?? MenuStrategy::Balanced)->value,
            'presupuesto_semanal' => $budget?->weeklyTarget,
            'despensa' => array_map(static fn (PantryStock $item): array => [
                'producto' => $item->productName,
                'cantidad' => $item->quantity,
                'unidad' => $item->unit?->value,
                'vence' => $item->expiresOn?->toDateString(),
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
- `despensa_cobertura` es la fracción de los ingredientes que la despensa ya cubre, calculada por el backend: 1.0 significa que no hace falta comprar nada. `vence_pronto` marca stock que vence en cuatro días. No los recalcules.
- Según `criterio`: `use_pantry` maximiza cobertura y aprovecha lo que vence; `save_money` minimiza lo que falta comprar; `balanced` reparte ambos. Evita repetir el mismo plato demasiado; respeta el tiempo máximo y el presupuesto semanal si está disponible.
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
     * @param  array<int, PantryStock>  $pantry
     * @return array<int, array{quantity: float, unit: Unit, expiresSoon: bool}>
     */
    private function pantryAvailabilityByProduct(array $pantry): array
    {
        $threshold = CarbonImmutable::now()->addDays(4);
        $available = [];

        foreach ($pantry as $stock) {
            if ($stock->unit === null || $stock->quantity <= 0.0) {
                continue;
            }

            $productId = $stock->productId;

            if (isset($available[$productId]) && $available[$productId]['unit'] !== $stock->unit) {
                continue;
            }

            $available[$productId] ??= ['quantity' => 0.0, 'unit' => $stock->unit, 'expiresSoon' => false];
            $available[$productId]['quantity'] = round($available[$productId]['quantity'] + $stock->quantity, 3);

            if ($stock->expiresOn !== null && $stock->expiresOn->lte($threshold)) {
                $available[$productId]['expiresSoon'] = true;
            }
        }

        return $available;
    }

    /**
     * Fracción de los ingredientes de la receta que el stock disponible ya
     * cubre, ponderada por cantidad. Es un hecho que el backend calcula y la IA
     * consume; nunca se delega la aritmética al modelo (ADR-0015).
     *
     * @param  array<int, array{quantity: float, unit: Unit, expiresSoon: bool}>  $available
     * @return array{cobertura: float, vence_pronto: bool}
     */
    private function coverageFor(Recipe $recipe, int $householdSize, array $available): array
    {
        $scale = $householdSize / max(1, (int) $recipe->base_servings);
        $needed = 0.0;
        $covered = 0.0;
        $expiresSoon = false;

        foreach ($recipe->ingredients as $ingredient) {
            if ($ingredient->is_optional) {
                continue;
            }

            $productUnit = Unit::tryFrom((string) $ingredient->product->unit);
            $ingredientUnit = Unit::tryFrom((string) $ingredient->unit);

            if ($productUnit === null || $ingredientUnit === null) {
                continue;
            }

            try {
                $quantity = UnitConverter::toCanonical(
                    (float) $ingredient->quantity * $scale,
                    $ingredientUnit->value,
                    $productUnit->value,
                );
            } catch (InvalidArgumentException) {
                continue;
            }

            $needed += $quantity;
            $stock = $available[(int) $ingredient->product->id] ?? null;

            if ($stock === null || $stock['unit'] !== $productUnit) {
                continue;
            }

            $covered += min($quantity, $stock['quantity']);

            if ($stock['expiresSoon']) {
                $expiresSoon = true;
            }
        }

        return [
            'cobertura' => $needed > 0.0 ? round(min(1.0, $covered / $needed), 3) : 0.0,
            'vence_pronto' => $expiresSoon,
        ];
    }

    /**
     * @param  array<int, Recipe>  $candidates
     * @param  array<int, PantryStock>  $pantry
     * @param  array<string, array{recipe_id: int, cook_servings: int}>  $selected
     */
    private function fallbackChoice(array $candidates, array $pantry, array $selected, MealPlanSettings $settings): ?Recipe
    {
        if ($candidates === []) {
            return null;
        }

        $available = $this->pantryAvailabilityByProduct($pantry);
        $household = (int) $settings->household_size;

        $usage = [];
        foreach ($selected as $choice) {
            $usage[$choice['recipe_id']] = ($usage[$choice['recipe_id']] ?? 0) + 1;
        }

        $scored = array_map(function (Recipe $recipe) use ($available, $usage, $household, $settings): array {
            $coverage = $this->coverageFor($recipe, $household, $available);
            $score = -($usage[(int) $recipe->id] ?? 0) * 5;

            $score += match ($settings->menu_strategy ?? MenuStrategy::Balanced) {
                MenuStrategy::UsePantry => $coverage['cobertura'] * 6 + ($coverage['vence_pronto'] ? 3 : 0),
                MenuStrategy::SaveMoney => $coverage['cobertura'] * 4,
                MenuStrategy::Balanced => $coverage['cobertura'] * 2 + ($coverage['vence_pronto'] ? 2 : 0),
            };

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

    /**
     * @param  array<string, mixed>  $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
