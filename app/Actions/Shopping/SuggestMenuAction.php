<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\DTOs\Prices\PriceContext;
use App\DTOs\Shopping\MenuPreferenceProfile;
use App\DTOs\Shopping\Nutrition;
use App\DTOs\Shopping\PantryStock;
use App\DTOs\Shopping\PlanningWeek;
use App\Enums\DietProfile;
use App\Enums\MealEntryKind;
use App\Enums\MealEntryStatus;
use App\Enums\MenuStrategy;
use App\Enums\RecipeCourse;
use App\Enums\RecipeCuisine;
use App\Enums\Unit;
use App\Models\MealPlanEntry;
use App\Models\MealPlanSettings;
use App\Models\Recipe;
use App\Repositories\Contracts\FoodBudgetRepositoryContract;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use App\Repositories\Contracts\PriceRepositoryContract;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use App\Services\AI\OpenCodeGateway;
use App\Services\Prices\PriceSourceRegistry;
use App\Services\Shopping\MenuCalendar;
use App\Services\Shopping\MenuCandidateFilter;
use App\Services\Shopping\MenuPreferenceProfileBuilder;
use App\Services\Shopping\MenuPriceEstimator;
use App\Services\Shopping\NutritionCalculator;
use App\Services\Shopping\PantryPriority;
use App\Services\Shopping\UnitConverter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Propone una semana completa y la guarda como entradas planificadas.
 *
 * La IA elige ids del catálogo ya filtrado; el backend valida cada id, la
 * comida y las porciones. La despensa se consume de forma secuencial mientras
 * se recorre la semana: cada receta elegida resta sus ingredientes de una copia
 * de trabajo, así el siguiente hueco ve menos stock y la semana reparte lo que
 * hay en vez de repetir el plato que más cubre.
 *
 * Si la IA falla o devuelve algo inválido, la semana se completa con una
 * elección determinística que usa la misma despensa agotada y respeta la
 * variedad. Nunca se inventan recetas ni ingredientes.
 */
final class SuggestMenuAction
{
    private const MAX_RECIPE_OCCURRENCES = 1;

    /** Cuántas comidas sin cocinar se reservan automáticamente en una sugerencia. */
    private const MAX_CARRY_OVER = 3;

    /** @var array<int, \App\Models\ConsumptionHabit> */
    private array $habits = [];

    /** @var array<int, int> */
    private array $habitProductIds = [];

    private const EXPIRING_SOON_DAYS = 4;

    /** @var array<int, \App\DTOs\Shopping\Nutrition> */
    private array $nutritionByRecipe = [];

    private ?PriceContext $priceContext = null;

    private ?CarbonImmutable $priceAsOf = null;

    private ?MenuPreferenceProfile $preferences = null;

    public function __construct(
        private readonly MealPlanRepositoryContract $mealPlanRepository,
        private readonly ShoppingRepositoryContract $shoppingRepository,
        private readonly FoodBudgetRepositoryContract $foodBudgetRepository,
        private readonly MenuCandidateFilter $candidateFilter,
        private readonly NutritionCalculator $nutritionCalculator,
        private readonly PantryPriority $pantryPriority,
        private readonly PriceRepositoryContract $priceRepository,
        private readonly PriceSourceRegistry $priceRegistry,
        private readonly MenuPriceEstimator $priceEstimator,
        private readonly MenuPreferenceProfileBuilder $preferenceBuilder,
        private readonly GeneratePantryRecipesAction $recipeGenerator,
        private readonly MenuCalendar $calendar,
        private readonly OpenCodeGateway $gateway,
    ) {}

    /**
     * Calcula la semana en una transacción y la revierte: las entradas y las
     * recetas propuestas quedan en memoria para el preview, pero nunca se
     * guardan. El usuario decide si las aplica (ADR-0027).
     *
     * @return array{source: 'ai'|'fallback', week: PlanningWeek, entries: \Illuminate\Database\Eloquent\Collection<int, \App\Models\MealPlanEntry>}
     */
    public function preview(int $userId, string $weekStart): array
    {
        $asOf = CarbonImmutable::now();
        $week = PlanningWeek::forWeekStarting(
            CarbonImmutable::parse($weekStart, 'America/Lima'),
            $asOf,
        );

        DB::beginTransaction();

        try {
            $source = $this->execute($userId, $week->startsOn->toDateString());
            $today = $week->asOf->setTimezone('America/Lima')->toDateString();
            $entries = $this->mealPlanRepository->entriesForWeek($userId, $week->startsOn, $week->endsOn)
                ->filter(fn ($entry): bool => $entry->plan_date->toDateString() >= $today)
                ->values();

            return ['source' => $source, 'week' => $week, 'entries' => $entries];
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @return 'ai'|'fallback'
     */
    public function execute(int $userId, string $weekStart): string
    {
        $asOf = CarbonImmutable::now();
        $week = PlanningWeek::forWeekStarting(CarbonImmutable::parse($weekStart, 'America/Lima'), $asOf);
        $settings = $this->mealPlanRepository->settingsFor($userId);
        $days = $this->calendar->upcomingActiveDays($week, $settings->plan_weekdays);

        if ($days === []) {
            return 'fallback';
        }

        $meals = $settings->meal_types;
        $this->nutritionByRecipe = [];

        try {
            $this->preferences = $this->preferenceBuilder->build(
                $this->mealPlanRepository->preferenceHistoryFor(
                    $userId,
                    $asOf->setTimezone('America/Lima')->startOfDay()->subDays(56),
                    $asOf->setTimezone('America/Lima')->startOfDay()->subDay(),
                ),
                $asOf,
            );
        } catch (Throwable $e) {
            report($e);
            $this->preferences = MenuPreferenceProfile::empty();
        }

        // Si una comida no tiene suficientes recetas distintas para los días
        // activos, la generación se enfoca en esas comidas. Así la semana no
        // queda con huecos solo porque faltaban candidatas.
        $preCandidates = $this->candidateFilter->forUser($userId, $settings);
        $focusMeals = $this->focusMealsFor($meals, $preCandidates, $settings, count($days));

        // El límite semanal es el único interruptor normal: 0 no llama a la IA
        // para recetas; 1–5 suma la generación. Si queda un hueco, la segunda
        // llamada de abajo puede ignorar el límite.
        try {
            $this->recipeGenerator->execute($userId, $week->startsOn->toDateString(), $focusMeals);
        } catch (Throwable $e) {
            report($e);
        }

        $this->nutritionByRecipe = [];
        $candidates = $this->candidateFilter->forUser($userId, $settings);
        $missing = [];

        foreach ($focusMeals as $meal) {
            $needed = count($days) - count($this->eligibleCandidates($candidates[$meal] ?? [], $settings, $meal));

            if ($needed > 0) {
                $missing[$meal] = $needed;
            }
        }

        if ($missing !== []) {
            try {
                $this->recipeGenerator->execute(
                    $userId,
                    $week->startsOn->toDateString(),
                    array_keys($missing),
                    true,
                    array_sum($missing),
                );
                $this->nutritionByRecipe = [];
                $candidates = $this->candidateFilter->forUser($userId, $settings);
            } catch (Throwable $e) {
                report($e);
            }
        }
        $pantry = $this->shoppingRepository->pantryStockFor($userId);
        $this->habits = $this->shoppingRepository->activeHabitsFor($userId);
        $this->habitProductIds = array_map(
            static fn ($habit): int => (int) $habit->product_id,
            $this->habits,
        );

        $this->priceAsOf = $asOf;
        $this->priceContext = ($settings->menu_strategy ?? MenuStrategy::Balanced) === MenuStrategy::SaveMoney
            ? $this->priceContextFor($candidates, $asOf)
            : null;

        $available = $this->pantryAvailabilityByProduct($pantry, $asOf);
        $priorityOrder = $this->pantryPriority->orderedProductIds($pantry, $asOf);
        $usage = [];
        $slotRecipe = [];
        $carryOver = $this->reservePendingMeals(
            $userId,
            $week,
            $settings,
            $days,
            $meals,
            $candidates,
            $available,
            $usage,
            $slotRecipe,
        );
        $reserved = array_fill_keys(array_keys($carryOver), true);
        $aiSelected = $this->fromAi(
            $week,
            $settings,
            $days,
            $meals,
            $candidates,
            $pantry,
            $available,
            $usage,
            $slotRecipe,
            $priorityOrder,
            $reserved,
        );
        $selected = $carryOver + $aiSelected;
        $source = $aiSelected === [] ? 'fallback' : 'ai';

        DB::transaction(function () use ($userId, $week, $settings, $days, $meals, $candidates, $selected, &$available, &$usage, &$slotRecipe, $priorityOrder): void {
            $this->mealPlanRepository->clearWeekFrom($userId, $week->startsOn, $week->endsOn, $week->asOf);

            $created = [];
            foreach ($selected as $key => $choice) {
                [$date, $meal] = explode('|', $key);
                $entry = $this->mealPlanRepository->upsertEntry($userId, [
                    'plan_date' => $date,
                    'meal_type' => $meal,
                    'kind' => 'recipe',
                    'recipe_id' => $choice['recipe_id'],
                    'drink_recipe_id' => $choice['drink_recipe_id'] ?? null,
                    'servings' => (int) $settings->household_size,
                    'cook_servings' => $choice['cook_servings'],
                    'leftover_of_entry_id' => null,
                    'rescheduled_from_entry_id' => $choice['rescheduled_from_entry_id'] ?? null,
                    'notes' => null,
                ]);
                $created[$key] = $entry;
            }

            // En ahorro, la cena usa las sobras del almuerzo antes de programar
            // sobras para otro día. Así la porción extra no se cuenta dos veces.
            $this->applySaveMoneyLunchLeftovers($userId, $settings, $days, $meals, $created);

            // Antes de rellenar, convertir las porciones extra de la IA en sobras.
            // Si se rellenara primero, cada día tendría una receta y ninguna sobra
            // encontraría un hueco.
            $created = $this->scheduleLeftovers($userId, $settings, $days, $meals, $created);

            foreach ($days as $date) {
                $sharedProductIds = $this->sharedProductIdsForDate($created, $date);

                foreach ($meals as $meal) {
                    $key = $date.'|'.$meal;
                    if (isset($created[$key])) {
                        continue;
                    }

                    $pick = $this->fallbackChoice(
                        $candidates[$meal] ?? [],
                        $available,
                        $usage,
                        $slotRecipe,
                        $date,
                        $meal,
                        $settings,
                        $priorityOrder,
                        $sharedProductIds,
                    );

                    if ($pick === null) {
                        continue;
                    }

                    $choice = $pick['recipe'];
                    $recipeId = (int) $choice->id;

                    $this->deplete($available, $pick['evaluation']['requirements']);
                    $usage[$recipeId] = ($usage[$recipeId] ?? 0) + 1;
                    $slotRecipe[$key] = $recipeId;

                    foreach (array_keys($pick['evaluation']['requirements']) as $productId) {
                        $sharedProductIds[$productId] = true;
                    }

                    $drinkPick = $this->chooseDrink(
                        $recipeId,
                        $candidates[$meal] ?? [],
                        $available,
                        $usage,
                        $settings,
                        $meal,
                        $sharedProductIds,
                    );

                    $drinkId = null;

                    if ($drinkPick !== null) {
                        $this->deplete($available, $drinkPick['evaluation']['requirements']);
                        $drinkId = (int) $drinkPick['recipe']->id;
                        $usage[$drinkId] = ($usage[$drinkId] ?? 0) + 1;

                        foreach (array_keys($drinkPick['evaluation']['requirements']) as $productId) {
                            $sharedProductIds[$productId] = true;
                        }
                    }

                    $selected[$key] = [
                        'recipe_id' => $recipeId,
                        'drink_recipe_id' => $drinkId,
                        'cook_servings' => (int) $settings->household_size,
                    ];

                    $created[$key] = $this->mealPlanRepository->upsertEntry($userId, [
                        'plan_date' => $date,
                        'meal_type' => $meal,
                        'kind' => 'recipe',
                        'recipe_id' => $recipeId,
                        'drink_recipe_id' => $drinkId,
                        'servings' => (int) $settings->household_size,
                        'cook_servings' => (int) $settings->household_size,
                        'leftover_of_entry_id' => null,
                        'notes' => null,
                    ]);
                }
            }

            $this->applySaveMoneyLunchLeftovers($userId, $settings, $days, $meals, $created);
        });

        return $source;
    }

    /**
     * Reserva los primeros huecos para las comidas pendientes que no se
     * cocinaron. Solo `planned` y `skipped` entran automáticamente: las sobras y
     * las comidas fuera ya resolvieron la ranura y se ofrecen para reprogramar
     * manualmente. El tope evita que la semana nueva sea un vertedero de
     * pendientes (ADR-0044).
     *
     * @param  array<int, string>  $days
     * @param  array<int, string>  $meals
     * @param  array<string, array<int, Recipe>>  $candidates
     * @param  array<int, array{quantity: float, unit: Unit, expiresSoon: bool}>  $available
     * @param  array<int, int>  $usage
     * @param  array<string, int>  $slotRecipe
     * @return array<string, array{recipe_id: int, drink_recipe_id: int|null, cook_servings: int, rescheduled_from_entry_id: int}>
     */
    private function reservePendingMeals(
        int $userId,
        PlanningWeek $week,
        MealPlanSettings $settings,
        array $days,
        array $meals,
        array $candidates,
        array &$available,
        array &$usage,
        array &$slotRecipe,
    ): array {
        $asOf = $week->asOf->setTimezone('America/Lima')->startOfDay();
        $pending = $this->mealPlanRepository
            ->pendingEntriesFor($userId, $asOf->subDays(7), $asOf->subDay())
            ->filter(static fn (MealPlanEntry $entry): bool => in_array($entry->status, [
                MealEntryStatus::Planned,
                MealEntryStatus::Skipped,
            ], true));

        $selected = [];
        $seenRecipes = [];

        foreach ($pending as $entry) {
            if (count($selected) >= self::MAX_CARRY_OVER) {
                break;
            }

            $recipeId = (int) $entry->recipe_id;
            $meal = $entry->meal_type->value;

            if (isset($seenRecipes[$recipeId]) || ! in_array($meal, $meals, true)) {
                continue;
            }

            $recipe = $this->findCandidate($candidates[$meal] ?? [], $recipeId);

            if ($recipe === null || $recipe->course === RecipeCourse::Drink) {
                continue;
            }

            $key = null;
            foreach ($days as $date) {
                $candidateKey = $date.'|'.$meal;

                if (! isset($selected[$candidateKey]) && ! isset($slotRecipe[$candidateKey])) {
                    $key = $candidateKey;
                    break;
                }
            }

            if ($key === null) {
                continue;
            }

            $servings = max(1, (int) $settings->household_size);
            $cook = max($servings, min((int) $entry->cook_servings, $servings * 2));
            $evaluation = $this->evaluate($recipe, $cook, $available);
            $drink = $entry->drink_recipe_id === null
                ? null
                : $this->findCandidate($candidates[$meal] ?? [], (int) $entry->drink_recipe_id);
            $drinkId = $drink !== null && $drink->course === RecipeCourse::Drink && (int) $drink->id !== $recipeId
                ? (int) $drink->id
                : null;

            $this->deplete($available, $evaluation['requirements']);
            $usage[$recipeId] = ($usage[$recipeId] ?? 0) + 1;
            $slotRecipe[$key] = $recipeId;

            if ($drinkId !== null) {
                $drinkEvaluation = $this->evaluate($drink, $servings, $available);
                $this->deplete($available, $drinkEvaluation['requirements']);
                $usage[$drinkId] = ($usage[$drinkId] ?? 0) + 1;
            }

            $selected[$key] = [
                'recipe_id' => $recipeId,
                'drink_recipe_id' => $drinkId,
                'cook_servings' => $cook,
                'rescheduled_from_entry_id' => (int) $entry->id,
            ];
            $seenRecipes[$recipeId] = true;
        }

        return $selected;
    }

    /**
     * @param  array<int, string>  $days
     * @param  array<int, string>  $meals
     * @param  array<string, array<int, Recipe>>  $candidates
     * @param  array<int, PantryStock>  $pantry
     * @param  array<int, array{quantity: float, unit: Unit, expiresSoon: bool}>  $available
     * @param  array<int, int>  $usage
     * @param  array<string, int>  $slotRecipe
     * @param  array<int, int>  $priorityOrder
     * @param  array<string, bool>  $reserved
     * @return array<string, array{recipe_id: int, drink_recipe_id: int|null, cook_servings: int}>
     */
    private function fromAi(
        PlanningWeek $week,
        MealPlanSettings $settings,
        array $days,
        array $meals,
        array $candidates,
        array $pantry,
        array &$available,
        array &$usage,
        array &$slotRecipe,
        array $priorityOrder,
        array $reserved = [],
    ): array {
        try {
            $response = $this->gateway->generateJson([
                ['parts' => [['text' => $this->prompt($week, $settings, $days, $meals, $candidates, $pantry, $available, $priorityOrder)]]],
            ], ['temperature' => 0.4]);
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        if ($response === null || ! isset($response['days']) || ! is_array($response['days'])) {
            return [];
        }

        $byMeal = [];
        foreach ($candidates as $meal => $recipes) {
            foreach ($recipes as $recipe) {
                $byMeal[$meal][(int) $recipe->id] = $recipe;
            }
        }

        $choices = [];
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
                if (! isset($byMeal[$meal][$recipeId])) {
                    continue;
                }

                $cook = (int) ($choice['cook_servings'] ?? $settings->household_size);
                $cook = max((int) $settings->household_size, min($cook, (int) $settings->household_size * 2));

                $drinkId = (int) ($choice['drink_recipe_id'] ?? 0);
                if ($drinkId > 0 && ! isset($byMeal[$meal][$drinkId])) {
                    $drinkId = 0;
                }

                $choices[$date.'|'.$meal] = [
                    'recipe_id' => $recipeId,
                    'drink_recipe_id' => $drinkId,
                    'cook_servings' => $cook,
                ];
            }
        }

        $profile = $settings->diet_profile ?? DietProfile::Balanced;
        $strategy = $settings->menu_strategy ?? MenuStrategy::Balanced;
        $selected = [];
        foreach ($days as $date) {
            foreach ($meals as $meal) {
                $key = $date.'|'.$meal;

                if (isset($reserved[$key])) {
                    continue;
                }

                $choice = $choices[$key] ?? null;

                if ($choice === null) {
                    continue;
                }

                $recipeId = $choice['recipe_id'];
                $recipe = $byMeal[$meal][$recipeId];

                if (! $this->canUseRecipe($recipeId, $date, $meal, $usage, $slotRecipe)) {
                    continue;
                }

                if ($recipe->course === RecipeCourse::Drink) {
                    continue;
                }

                if ($this->hasLessUsedAlternative($candidates[$meal] ?? [], $recipeId, $date, $meal, $usage, $slotRecipe)) {
                    continue;
                }

                // Alto en proteína sigue siendo una preferencia fuerte. Ligero
                // es solo un bonus de puntaje: con pocas recetas etiquetadas, el
                // filtro fuerte reducía la semana a un único plato (ADR-0033).
                if ($profile === DietProfile::HighProtein
                    && ! in_array('high_protein', $recipe->diet_tags ?? [], true)
                    && $this->hasDietAlternative($candidates[$meal] ?? [], $recipeId, 'high_protein', $date, $meal, $usage, $slotRecipe)) {
                    continue;
                }

                $carbLimit = $settings->dinner_carb_limit_g;

                if ($meal === 'dinner'
                    && $carbLimit !== null
                    && $this->nutritionOf($recipe)->isComplete()
                    && $this->nutritionOf($recipe)->carbsG > $carbLimit
                    && $this->hasLowCarbAlternative($candidates[$meal] ?? [], $recipeId, $date, $meal, $usage, $slotRecipe, $carbLimit)) {
                    continue;
                }

                $calorieLimit = $settings->dinner_calorie_limit;

                if ($meal === 'dinner'
                    && $calorieLimit !== null
                    && $this->nutritionOf($recipe)->isComplete()
                    && $this->nutritionOf($recipe)->kcal > $calorieLimit
                    && $this->hasDinnerCalorieAlternative($candidates[$meal] ?? [], $recipeId, $date, $meal, $usage, $slotRecipe, $calorieLimit)) {
                    continue;
                }

                $priorityProductId = ($settings->menu_strategy ?? MenuStrategy::Balanced) === MenuStrategy::UsePantry
                    ? $this->priorityProductFor($candidates[$meal] ?? [], $priorityOrder, $available, $date, $meal, $usage, $slotRecipe)
                    : null;

                if ($priorityProductId !== null && ! $this->recipeUsesProduct($recipe, $priorityProductId)) {
                    continue;
                }

                $evaluation = $this->evaluate($recipe, $choice['cook_servings'], $available);

                if ($strategy === MenuStrategy::SaveMoney
                    && $this->hasCheaperAlternative(
                        $candidates[$meal] ?? [],
                        $recipeId,
                        $evaluation['costo_centavos'],
                        $choice['cook_servings'],
                        $available,
                        $date,
                        $meal,
                        $usage,
                        $slotRecipe,
                    )) {
                    continue;
                }

                if (($settings->menu_strategy ?? MenuStrategy::Balanced) === MenuStrategy::UsePantry
                    && $evaluation['aprovechamiento'] <= 0.0
                    && $this->hasPantryAlternative(
                        $candidates[$meal] ?? [],
                        $recipeId,
                        $available,
                        $usage,
                        $slotRecipe,
                        $date,
                        $meal,
                        (int) $settings->household_size,
                    )) {
                    continue;
                }

                $selected[$key] = $choice;
                $this->deplete($available, $evaluation['requirements']);
                $usage[$recipeId] = ($usage[$recipeId] ?? 0) + 1;
                $slotRecipe[$key] = $recipeId;

                $drinkPick = $this->resolveDrink(
                    $choice['drink_recipe_id'],
                    $recipeId,
                    $candidates[$meal] ?? [],
                    $available,
                    $usage,
                    $settings,
                    $meal,
                );

                if ($drinkPick !== null) {
                    $this->deplete($available, $drinkPick['evaluation']['requirements']);
                    $drinkId = (int) $drinkPick['recipe']->id;
                    $usage[$drinkId] = ($usage[$drinkId] ?? 0) + 1;
                    $selected[$key]['drink_recipe_id'] = $drinkId;
                } else {
                    $selected[$key]['drink_recipe_id'] = null;
                }
            }
        }

        return $selected;
    }

    /**
     * @param  array<int, string>  $days
     * @param  array<int, string>  $meals
     * @param  array<string, array<int, Recipe>>  $candidates
     * @param  array<int, PantryStock>  $pantry
     * @param  array<int, array{quantity: float, unit: Unit, expiresSoon: bool}>  $available
     * @param  array<int, int>  $priorityOrder
     */
    private function prompt(
        PlanningWeek $week,
        MealPlanSettings $settings,
        array $days,
        array $meals,
        array $candidates,
        array $pantry,
        array $available,
        array $priorityOrder,
    ): string {
        $budget = $this->foodBudgetRepository->snapshotFor($settings->user_id, CarbonImmutable::now());
        $today = $week->asOf->setTimezone('America/Lima')->toDateString();

        $recipes = [];
        foreach ($candidates as $meal => $items) {
            foreach ($items as $recipe) {
                $evaluation = $this->evaluate($recipe, (int) $settings->household_size, $available);

                $recipes[] = [
                    'id' => (int) $recipe->id,
                    'nombre' => $recipe->name,
                    'comida' => $meal,
                    'comidas' => $recipe->meal_types,
                    'tipo' => $recipe->course->value,
                    'cocina' => $recipe->cuisine->value,
                    'diet_tags' => $recipe->diet_tags ?? [],
                    'productos' => $recipe->ingredients
                        ->map(static fn ($ingredient): string => (string) $ingredient->product->name)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                    'nutricion' => $this->nutritionOf($recipe)->toArray(),
                    'minutos' => $recipe->prep_minutes,
                    'despensa_cobertura' => $evaluation['cobertura'],
                    'despensa_aprovechamiento' => $evaluation['aprovechamiento'],
                    'vence_pronto' => $evaluation['vence_pronto'],
                    'faltantes' => $evaluation['faltantes'],
                    'costo_faltantes' => $evaluation['costo_centavos'] !== null
                        ? round($evaluation['costo_centavos'] / 100, 2)
                        : null,
                    'costo_completo' => $evaluation['costo_completo'],
                    'productos_sin_precio' => $evaluation['costo_sin_precio'],
                ];
            }
        }

        $priorityDays = $this->pantryPriority->daysLeftByProduct($pantry, $week->asOf);
        $priorityPosition = array_flip($priorityOrder);

        $visiblePantry = array_values(array_filter(
            $pantry,
            function (PantryStock $item) use ($today, $week): bool {
                if ($item->unit === null || $item->quantity <= 0.0) {
                    return false;
                }

                if ($item->expiresOn !== null && $item->expiresOn->toDateString() < $today) {
                    return false;
                }

                $daysLeft = $this->pantryPriority->daysLeft($item, $week->asOf);

                return $daysLeft === null || $daysLeft >= 0;
            },
        ));

        $context = [
            'semana' => ['inicio' => $week->startsOn->toDateString(), 'fin' => $week->endsOn->toDateString()],
            'personas' => (int) $settings->household_size,
            'comidas_activas' => $meals,
            'dias_activos' => $days,
            'criterio' => ($settings->menu_strategy ?? MenuStrategy::Balanced)->value,
            'perfil' => ($settings->diet_profile ?? DietProfile::Balanced)->value,
            'objetivo_kcal_dia' => $settings->daily_calorie_target,
            'limite_carbohidratos_cena_g' => $settings->dinner_carb_limit_g,
            'limite_minutos_cena' => $settings->dinner_max_prep_minutes,
            'limite_maximo_cena' => $settings->dinner_max_prep_minutes !== null
                ? $settings->dinner_max_prep_minutes + MenuCandidateFilter::DINNER_PREP_TOLERANCE_MINUTES
                : null,
            'limite_calorias_cena' => $settings->dinner_calorie_limit,
            'cocina_preferida' => RecipeCuisine::Peruvian->value,
            'preferencias_aprendidas' => $this->preferences?->hasSignal === true
                ? $this->preferences->toPromptArray()
                : null,
            'presupuesto_semanal' => $budget?->weeklyTarget,
            'despensa' => array_map(fn (PantryStock $item): array => [
                'producto' => $item->productName,
                'cantidad' => $item->quantity,
                'unidad' => $item->unit?->value,
                'vence' => $item->expiresOn?->toDateString(),
                'dias_para_vencer' => $priorityDays[$item->productId] ?? null,
                'prioridad' => isset($priorityPosition[$item->productId]) ? $priorityPosition[$item->productId] + 1 : null,
            ], $visiblePantry),
            'habitos' => array_map(static fn ($habit): array => [
                'producto_id' => (int) $habit->product_id,
                'producto' => (string) $habit->product?->name,
                'cantidad_semanal' => (float) $habit->weekly_quantity,
                'unidad' => (string) $habit->unit,
            ], $this->habits),
            'recetas' => $recipes,
        ];

        return <<<PROMPT
Eres un planificador de menú semanal para una familia peruana. Elige recetas del catálogo entregado y devuelve solo JSON válido.

Reglas:
- Usa únicamente recipe_id que aparezcan en el catálogo. No inventes recetas, ingredientes ni ids.
- Elige solo comidas compatibles con cada receta.
- Para cada día activo y cada comida activa, elige una receta o deja la comida vacía si no hay candidata.
- servings siempre es el número de personas. cook_servings debe ser >= personas; usa hasta 2x personas si quieres dejar sobras para el día siguiente.
- `despensa_cobertura` es la fracción de ingredientes que la despensa cubre. `despensa_aprovechamiento` es cuánto de la despensa consume la receta: suma, por producto, de la fracción del stock que se lleva. `faltantes` son los productos que habría que comprar. Los tres los calcula el backend; no los recalcules.
- `vence_pronto` marca stock que vence en cuatro días.
- `tipo` es `main` (plato principal), `drink` (bebida) o `side` (acompañamiento). El `recipe_id` nunca es una `drink`: la bebida va en `drink_recipe_id`. Si el catálogo tiene una bebida para esa comida, úsala como acompañamiento; en el desayuno es lo esperado.
- `drink_recipe_id` debe ser distinto de `recipe_id`, estar en el catálogo y ser una `drink`.
- Prefiere recetas que usen los productos de `habitos`; no es obligatorio, pero ayuda a cubrir lo que el usuario declaró.
- Según `criterio`: `use_pantry` empieza por el producto de `despensa` con `prioridad` más baja; si una receta lo usa, elígela aunque falten otros ingredientes, porque los faltantes se compran. No elijas una receta que ignore ese producto si otra candidata lo usa. `save_money` minimiza `costo_faltantes` en soles; no elijas una receta cara si hay una más barata. `costo_completo = false` significa que falta precio, no que sea gratis. `balanced` prioriza variedad y perfil, con la cobertura como desempate; no busca vaciar la despensa.
- `productos` es la lista compacta de ingredientes de cada receta. Reutiliza productos base entre comidas del mismo día: si el desayuno ya usa quinua, arroz, lentejas o un producto similar, prefiere un almuerzo o cena que use el mismo producto para cocinarlo una sola vez.
- `preferencias_aprendidas` es una tendencia suave de las últimas ocho semanas; úsala para desempatar, no para repetir ni excluir recetas. Respeta la variedad y el perfil.
- La cena ya viene filtrada hasta `limite_maximo_cena`; prefiere `limite_minutos_cena` y usa el margen de cinco minutos solo si no hay otra cena. Prefiere recetas `light`. Si `limite_calorias_cena` no es null, elige una cena por debajo y evita platos de fondo pesados si hay una opción ligera.
- En `save_money`, si el almuerzo es compatible con la cena, cocina para dos comidas (cook_servings = porciones del almuerzo + personas de la cena) y deja la cena vacía; el backend la convertirá en sobras del almuerzo. Para que sea sobra, el almuerzo debe ser `light` y no llevar pescado fresco ni mariscos.
- Según `perfil`: `vegetarian` y `vegan` ya vienen filtrados por el backend; `high_protein` y `light` prefieren recetas con esa etiqueta en `diet_tags`.
- `nutricion` es aproximada y por porción; no la recalcules ni la inventes. Si `nutricion.is_complete` es false, no la uses para decidir el objetivo. Si `objetivo_kcal_dia` no es null, reparte el objetivo entre las comidas activas y evita que un día quede muy por encima. Si `limite_carbohidratos_cena_g` no es null, prefiere cenas con `carbs_g` por debajo del límite; si no hay alternativa, elige la de menos carbohidratos.
- Usa cada receta una sola vez en la semana; no repitas el mismo plato. Tampoco repitas la misma receta en días consecutivos para la misma comida. Respeta el tiempo máximo y el presupuesto semanal si está disponible.
- No expliques nada fuera del JSON.

Contexto:
```json
{$this->json($context)}
```

Formato exacto:
{"days":[{"date":"YYYY-MM-DD","meals":{"breakfast":{"recipe_id":1,"drink_recipe_id":2,"cook_servings":2},"lunch":{"recipe_id":3,"cook_servings":4}}}]}
PROMPT;
    }

    /**
     * @param  array<int, PantryStock>  $pantry
     * @return array<int, array{quantity: float, unit: Unit, expiresSoon: bool}>
     */
    private function pantryAvailabilityByProduct(array $pantry, CarbonImmutable $asOf): array
    {
        $local = $asOf->setTimezone('America/Lima');
        $today = $local->toDateString();
        $available = [];

        foreach ($pantry as $stock) {
            if ($stock->unit === null || $stock->quantity <= 0.0) {
                continue;
            }

            if ($stock->expiresOn !== null && $stock->expiresOn->toDateString() < $today) {
                continue;
            }

            $daysLeft = $this->pantryPriority->daysLeft($stock, $local);

            if ($daysLeft !== null && $daysLeft < 0) {
                continue;
            }

            $productId = $stock->productId;

            if (isset($available[$productId]) && $available[$productId]['unit'] !== $stock->unit) {
                continue;
            }

            $available[$productId] ??= ['quantity' => 0.0, 'unit' => $stock->unit, 'expiresSoon' => false];
            $available[$productId]['quantity'] = round($available[$productId]['quantity'] + $stock->quantity, 3);

            if ($daysLeft !== null && $daysLeft <= self::EXPIRING_SOON_DAYS) {
                $available[$productId]['expiresSoon'] = true;
            }
        }

        return $available;
    }

    /**
     * Evalúa una receta contra el stock que queda en este punto de la semana.
     *
     * `aprovechamiento` es unitless a propósito: suma, por producto, la
     * fracción del stock disponible que la receta se lleva. Así una receta que
     * vacía dos productos compite mejor que otra que apenas toca uno, sin sumar
     * kilos con unidades.
     *
     * @param  array<int, array{quantity: float, unit: Unit, expiresSoon: bool}>  $available
     * @return array{
     *     requirements: array<int, array{name: string, unit: Unit, quantity: float}>,
     *     cobertura: float,
     *     aprovechamiento: float,
     *     faltantes: array<int, string>,
     *     vence_pronto: bool,
     *     costo_centavos: int|null,
     *     costo_completo: bool,
     *     costo_sin_precio: int
     * }
     */
    private function evaluate(Recipe $recipe, int $cookServings, array $available): array
    {
        $requirements = $this->requirementsFor($recipe, $cookServings);
        $needed = 0.0;
        $covered = 0.0;
        $aprovechamiento = 0.0;
        $expiresSoon = false;
        $faltantes = [];

        foreach ($requirements as $productId => $requirement) {
            $needed += $requirement['quantity'];
            $stock = $available[$productId] ?? null;
            $used = 0.0;

            if ($stock !== null && $stock['unit'] === $requirement['unit']) {
                $used = min($requirement['quantity'], $stock['quantity']);

                if ($stock['quantity'] > 0.0) {
                    $aprovechamiento += $used / $stock['quantity'];
                }

                if ($used > 0.0 && $stock['expiresSoon']) {
                    $expiresSoon = true;
                }
            }

            $covered += $used;

            if (round($used, 3) < round($requirement['quantity'], 3)) {
                $faltantes[] = $requirement['name'];
            }
        }

        $cost = $this->priceEstimator->estimate(
            $requirements,
            $available,
            $this->priceContext,
            $this->priceAsOf ?? CarbonImmutable::now(),
        );

        return [
            'requirements' => $requirements,
            'cobertura' => $needed > 0.0 ? round(min(1.0, $covered / $needed), 3) : 0.0,
            'aprovechamiento' => round($aprovechamiento, 3),
            'faltantes' => array_values(array_unique($faltantes)),
            'vence_pronto' => $expiresSoon,
            'costo_centavos' => $cost['cost_cents'],
            'costo_completo' => $cost['complete'],
            'costo_sin_precio' => $cost['unpriced'],
        ];
    }

    /**
     * @return array<int, array{name: string, unit: Unit, quantity: float}>
     */
    private function requirementsFor(Recipe $recipe, int $cookServings): array
    {
        $scale = max(1, $cookServings) / max(1, (int) $recipe->base_servings);
        $requirements = [];

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

            $productId = (int) $ingredient->product->id;
            $requirements[$productId] ??= [
                'name' => (string) $ingredient->product->name,
                'unit' => $productUnit,
                'quantity' => 0.0,
            ];

            if ($requirements[$productId]['unit'] !== $productUnit) {
                continue;
            }

            $requirements[$productId]['quantity'] = round($requirements[$productId]['quantity'] + $quantity, 3);
        }

        return $requirements;
    }

    /**
     * @param  array<int, array{name: string, unit: Unit, quantity: float}>  $requirements
     * @param  array<int, array{quantity: float, unit: Unit, expiresSoon: bool}>  $available
     */
    private function deplete(array &$available, array $requirements): void
    {
        foreach ($requirements as $productId => $requirement) {
            if (! isset($available[$productId]) || $available[$productId]['unit'] !== $requirement['unit']) {
                continue;
            }

            $available[$productId]['quantity'] = round(
                max(0.0, $available[$productId]['quantity'] - $requirement['quantity']),
                3,
            );
        }
    }

    /**
     * Usa la bebida que propuso la IA si es válida; si no, elige una del catálogo
     * para esa comida. Si no hay bebidas, la comida queda solo con el plato.
     *
     * @param  array<int, Recipe>  $candidates
     * @param  array<int, array{quantity: float, unit: Unit, expiresSoon: bool}>  $available
     * @param  array<int, int>  $usage
     * @param  array<int, true>  $sharedProductIds
     * @return array{
     *     recipe: Recipe,
     *     evaluation: array{
     *         requirements: array<int, array{name: string, unit: Unit, quantity: float}>,
     *         cobertura: float,
     *         aprovechamiento: float,
     *         faltantes: array<int, string>,
     *         vence_pronto: bool,
     *         costo_centavos: int|null,
     *         costo_completo: bool,
     *         costo_sin_precio: int
     *     }
     * }|null
     */
    private function resolveDrink(
        int $drinkId,
        int $mainId,
        array $candidates,
        array $available,
        array $usage,
        MealPlanSettings $settings,
        string $meal,
        array $sharedProductIds = [],
    ): ?array {
        if ($drinkId > 0 && $drinkId !== $mainId) {
            $drink = $this->findCandidate($candidates, $drinkId);

            if ($drink !== null
                && $this->canUseDrink($drinkId, $usage)
                && $drink->course === RecipeCourse::Drink) {
                return [
                    'recipe' => $drink,
                    'evaluation' => $this->evaluate($drink, (int) $settings->household_size, $available),
                ];
            }
        }

        return $this->chooseDrink($mainId, $candidates, $available, $usage, $settings, $meal, $sharedProductIds);
    }

    /**
     * Una bebida para la comida, elegida con el mismo criterio que el plato
     * principal. No aplica la regla de días consecutivos: una bebida puede
     * repetirse más seguido que un plato.
     *
     * @param  array<int, Recipe>  $candidates
     * @param  array<int, array{quantity: float, unit: Unit, expiresSoon: bool}>  $available
     * @param  array<int, int>  $usage
     * @param  array<int, true>  $sharedProductIds
     * @return array{
     *     recipe: Recipe,
     *     evaluation: array{
     *         requirements: array<int, array{name: string, unit: Unit, quantity: float}>,
     *         cobertura: float,
     *         aprovechamiento: float,
     *         faltantes: array<int, string>,
     *         vence_pronto: bool,
     *         costo_centavos: int|null,
     *         costo_completo: bool,
     *         costo_sin_precio: int
     *     }
     * }|null
     */
    private function chooseDrink(
        int $mainId,
        array $candidates,
        array $available,
        array $usage,
        MealPlanSettings $settings,
        string $meal,
        array $sharedProductIds = [],
    ): ?array {
        $drinks = array_values(array_filter(
            $candidates,
            static fn (Recipe $recipe): bool => $recipe->course === RecipeCourse::Drink
                && (int) $recipe->id !== $mainId,
        ));

        if ($drinks === []) {
            return null;
        }

        $usable = array_values(array_filter(
            $drinks,
            fn (Recipe $recipe): bool => $this->canUseDrink((int) $recipe->id, $usage),
        ));

        if ($usable === []) {
            $usable = $drinks;
        }

        $strategy = $settings->menu_strategy ?? MenuStrategy::Balanced;
        $profile = $settings->diet_profile ?? DietProfile::Balanced;
        $evaluated = array_map(fn (Recipe $recipe): array => [
            'recipe' => $recipe,
            'evaluation' => $this->evaluate($recipe, (int) $settings->household_size, $available),
        ], $usable);

        if ($strategy === MenuStrategy::UsePantry) {
            $withPantry = array_values(array_filter(
                $evaluated,
                static fn (array $row): bool => $row['evaluation']['aprovechamiento'] > 0.0,
            ));

            if ($withPantry !== []) {
                $evaluated = $withPantry;
            }
        }

        usort($evaluated, function (array $a, array $b) use ($strategy, $profile, $usage, $meal, $settings, $sharedProductIds): int {
            $scoreA = $this->scoreRecipe($a['evaluation'], $strategy, $usage[(int) $a['recipe']->id] ?? 0, $a['recipe'], $profile, $meal, $settings, $sharedProductIds);
            $scoreB = $this->scoreRecipe($b['evaluation'], $strategy, $usage[(int) $b['recipe']->id] ?? 0, $b['recipe'], $profile, $meal, $settings, $sharedProductIds);

            return $scoreB <=> $scoreA ?: $a['recipe']->name <=> $b['recipe']->name;
        });

        return $evaluated[0];
    }

    /**
     * @param  array<int, int>  $usage
     */
    private function canUseDrink(int $recipeId, array $usage): bool
    {
        return ($usage[$recipeId] ?? 0) < self::MAX_RECIPE_OCCURRENCES;
    }

    /**
     * @param  array<int, Recipe>  $candidates
     */
    private function findCandidate(array $candidates, int $recipeId): ?Recipe
    {
        foreach ($candidates as $recipe) {
            if ((int) $recipe->id === $recipeId) {
                return $recipe;
            }
        }

        return null;
    }

    /**
     * @param  array<int, Recipe>  $candidates
     * @param  array<int, array{quantity: float, unit: Unit, expiresSoon: bool}>  $available
     * @param  array<int, int>  $usage
     * @param  array<string, int>  $slotRecipe
     * @param  array<int, int>  $priorityOrder
     * @param  array<int, true>  $sharedProductIds
     * @return array{
     *     recipe: Recipe,
     *     evaluation: array{
     *         requirements: array<int, array{name: string, unit: Unit, quantity: float}>,
     *         cobertura: float,
     *         aprovechamiento: float,
     *         faltantes: array<int, string>,
     *         vence_pronto: bool,
     *         costo_centavos: int|null,
     *         costo_completo: bool,
     *         costo_sin_precio: int
     *     }
     * }|null
     */
    private function fallbackChoice(
        array $candidates,
        array $available,
        array $usage,
        array $slotRecipe,
        string $date,
        string $meal,
        MealPlanSettings $settings,
        array $priorityOrder,
        array $sharedProductIds,
    ): ?array {
        if ($candidates === []) {
            return null;
        }

        // El plato principal nunca es una bebida; la bebida va en su propia
        // ranura. Si no hay platos, la comida queda vacía para que el usuario
        // agregue uno, en vez de proponer una bebida sola.
        $mains = array_values(array_filter(
            $candidates,
            static fn (Recipe $recipe): bool => $recipe->course !== RecipeCourse::Drink,
        ));

        if ($mains === []) {
            return null;
        }

        $candidates = $mains;

        $household = (int) $settings->household_size;
        $strategy = $settings->menu_strategy ?? MenuStrategy::Balanced;
        $profile = $settings->diet_profile ?? DietProfile::Balanced;

        if ($profile === DietProfile::HighProtein) {
            $withTag = array_values(array_filter(
                $candidates,
                static fn (Recipe $recipe): bool => in_array('high_protein', $recipe->diet_tags ?? [], true),
            ));

            if ($withTag !== []) {
                $candidates = $withTag;
            }
        }

        $limit = $settings->dinner_carb_limit_g;

        if ($meal === 'dinner' && $limit !== null) {
            $lowCarb = array_values(array_filter(
                $candidates,
                fn (Recipe $recipe): bool => $this->nutritionOf($recipe)->isComplete()
                    && $this->nutritionOf($recipe)->carbsG <= $limit,
            ));

            if ($lowCarb !== []) {
                $candidates = $lowCarb;
            }
        }

        $calorieLimit = $settings->dinner_calorie_limit;

        if ($meal === 'dinner' && $calorieLimit !== null) {
            $lowerCalories = array_values(array_filter(
                $candidates,
                fn (Recipe $recipe): bool => $this->nutritionOf($recipe)->isComplete()
                    && $this->nutritionOf($recipe)->kcal <= $calorieLimit,
            ));

            if ($lowerCalories !== []) {
                $candidates = $lowerCalories;
            }
        }

        if ($strategy === MenuStrategy::UsePantry) {
            $priorityProductId = $this->priorityProductFor($candidates, $priorityOrder, $available, $date, $meal, $usage, $slotRecipe);

            if ($priorityProductId !== null) {
                $withPriority = array_values(array_filter(
                    $candidates,
                    fn (Recipe $recipe): bool => $this->recipeUsesProduct($recipe, $priorityProductId),
                ));

                if ($withPriority !== []) {
                    $candidates = $withPriority;
                }
            }
        }

        $usable = array_values(array_filter(
            $candidates,
            fn (Recipe $recipe): bool => $this->canUseRecipe((int) $recipe->id, $date, $meal, $usage, $slotRecipe),
        ));

        // Sin repetir, un hueco se queda vacío en vez de repetir un plato. El
        // usuario puede completarlo a mano o pedir una receta nueva.
        if ($usable === []) {
            return null;
        }

        // Primero las que todavía no se usaron; después las de una sola
        // aparición. Así la semana rota por todo el recetario antes de repetir.
        $minimumUsage = min(array_map(
            static fn (Recipe $recipe): int => $usage[(int) $recipe->id] ?? 0,
            $usable,
        ));
        $usable = array_values(array_filter(
            $usable,
            static fn (Recipe $recipe): bool => ($usage[(int) $recipe->id] ?? 0) === $minimumUsage,
        ));

        $evaluated = array_map(fn (Recipe $recipe): array => [
            'recipe' => $recipe,
            'evaluation' => $this->evaluate($recipe, $household, $available),
        ], $usable);

        if ($strategy === MenuStrategy::UsePantry) {
            $withPantry = array_values(array_filter(
                $evaluated,
                static fn (array $row): bool => $row['evaluation']['aprovechamiento'] > 0.0,
            ));

            if ($withPantry !== []) {
                $evaluated = $withPantry;
            }
        }

        usort($evaluated, function (array $a, array $b) use ($strategy, $profile, $usage, $meal, $settings, $sharedProductIds): int {
            $scoreA = $this->scoreRecipe(
                $a['evaluation'],
                $strategy,
                $usage[(int) $a['recipe']->id] ?? 0,
                $a['recipe'],
                $profile,
                $meal,
                $settings,
                $sharedProductIds,
            );
            $scoreB = $this->scoreRecipe(
                $b['evaluation'],
                $strategy,
                $usage[(int) $b['recipe']->id] ?? 0,
                $b['recipe'],
                $profile,
                $meal,
                $settings,
                $sharedProductIds,
            );

            return $scoreB <=> $scoreA ?: $a['recipe']->name <=> $b['recipe']->name;
        });

        return $evaluated[0];
    }

    /**
     * @param  array{
     *     requirements: array<int, array{name: string, unit: Unit, quantity: float}>,
     *     cobertura: float,
     *     aprovechamiento: float,
     *     faltantes: array<int, string>,
     *     vence_pronto: bool,
     *     costo_centavos: int|null,
     *     costo_completo: bool,
     *     costo_sin_precio: int
     * }  $evaluation
     * @param  array<int, true>  $sharedProductIds
     */
    private function scoreRecipe(
        array $evaluation,
        MenuStrategy $strategy,
        int $occurrences,
        Recipe $recipe,
        DietProfile $profile,
        string $meal,
        MealPlanSettings $settings,
        array $sharedProductIds = [],
    ): float {
        $varietyPenalty = $occurrences * 6.0;
        $dietBonus = match ($profile) {
            DietProfile::HighProtein => in_array('high_protein', $recipe->diet_tags ?? [], true) ? 3.0 : 0.0,
            DietProfile::Light => in_array('light', $recipe->diet_tags ?? [], true) ? 5.0 : 0.0,
            default => 0.0,
        };
        $habitBonus = 0.0;
        $cuisineBonus = ($recipe->cuisine ?? RecipeCuisine::Peruvian) === RecipeCuisine::Peruvian ? 2.0 : 0.0;

        foreach ($recipe->ingredients as $ingredient) {
            if (in_array((int) $ingredient->product_id, $this->habitProductIds, true)) {
                $habitBonus = 2.0;
                break;
            }
        }

        $sharedBonus = 0.0;

        foreach ($recipe->ingredients as $ingredient) {
            if (isset($sharedProductIds[(int) $ingredient->product_id])) {
                $sharedBonus += 1.5;
            }
        }

        $sharedBonus = min(3.0, $sharedBonus);
        $dinnerBonus = 0.0;

        if ($meal === 'dinner') {
            if (in_array('light', $recipe->diet_tags ?? [], true)) {
                $dinnerBonus += 5.0;
            }

            $calorieLimit = $settings->dinner_calorie_limit;
            $nutrition = $this->nutritionOf($recipe);

            if ($calorieLimit !== null && $nutrition->isComplete()) {
                $dinnerBonus += $nutrition->kcal <= $calorieLimit
                    ? 3.0
                    : -min(10.0, ($nutrition->kcal - $calorieLimit) / 50.0);
            }

            $targetMinutes = $settings->dinner_max_prep_minutes;

            if ($targetMinutes !== null && $recipe->prep_minutes !== null) {
                $delay = $recipe->prep_minutes - $targetMinutes;

                if ($delay > 0) {
                    $dinnerBonus -= min(4.0, $delay * 0.8);
                }
            }
        }

        return $dietBonus + $habitBonus + $cuisineBonus + $sharedBonus + $dinnerBonus + $this->preferenceScore($recipe, $meal) + $this->nutritionScore($recipe, $meal, $settings) + match ($strategy) {
            MenuStrategy::UsePantry => $evaluation['aprovechamiento'] * 6.0
                + $evaluation['cobertura'] * 2.0
                + ($evaluation['vence_pronto'] ? 2.0 : 0.0)
                - $varietyPenalty,
            MenuStrategy::SaveMoney => $this->saveMoneyCostScore($evaluation)
                + $evaluation['cobertura'] * 2.0
                + ($evaluation['vence_pronto'] ? 1.0 : 0.0)
                - $varietyPenalty,
            MenuStrategy::Balanced => $evaluation['cobertura'] * 4.0
                + ($evaluation['vence_pronto'] ? 2.0 : 0.0)
                - $varietyPenalty,
        };
    }

    /**
     * @param  array<int, string>  $meals
     * @param  array<string, array<int, Recipe>>  $candidates
     * @return array<int, string>
     */
    private function focusMealsFor(array $meals, array $candidates, MealPlanSettings $settings, int $days): array
    {
        $focus = [];

        foreach ($meals as $meal) {
            if (count($this->eligibleCandidates($candidates[$meal] ?? [], $settings, $meal)) < $days) {
                $focus[] = $meal;
            }
        }

        return $focus;
    }

    /**
     * @param  array<int, Recipe>  $recipes
     * @return array<int, Recipe>
     */
    private function eligibleCandidates(array $recipes, MealPlanSettings $settings, string $meal): array
    {
        $eligible = $recipes;

        if (($settings->diet_profile ?? DietProfile::Balanced) === DietProfile::HighProtein) {
            $tagged = array_values(array_filter(
                $eligible,
                static fn (Recipe $recipe): bool => in_array('high_protein', $recipe->diet_tags ?? [], true),
            ));

            if ($tagged !== []) {
                $eligible = $tagged;
            }
        }

        if ($meal !== 'dinner') {
            return $eligible;
        }

        $carbLimit = $settings->dinner_carb_limit_g;

        if ($carbLimit !== null) {
            $eligible = array_values(array_filter(
                $eligible,
                fn (Recipe $recipe): bool => ! $this->nutritionOf($recipe)->isComplete()
                    || $this->nutritionOf($recipe)->carbsG <= $carbLimit,
            ));
        }

        $calorieLimit = $settings->dinner_calorie_limit;

        if ($calorieLimit !== null) {
            $eligible = array_values(array_filter(
                $eligible,
                fn (Recipe $recipe): bool => ! $this->nutritionOf($recipe)->isComplete()
                    || $this->nutritionOf($recipe)->kcal <= $calorieLimit,
            ));
        }

        return $eligible;
    }

    /**
     * @param  array{costo_centavos: int|null, costo_completo: bool, costo_sin_precio: int}  $evaluation
     */
    private function saveMoneyCostScore(array $evaluation): float
    {
        $cost = $evaluation['costo_centavos'];

        if ($cost === null) {
            return -4.0;
        }

        $score = -min(12.0, $cost / 100.0);

        if (! $evaluation['costo_completo']) {
            $score -= 3.0;
        }

        return $score;
    }

    private function preferenceScore(Recipe $recipe, string $meal): float
    {
        $profile = $this->preferences;

        if ($profile === null || ! $profile->hasSignal) {
            return 0.0;
        }

        $recipeId = (int) $recipe->id;
        $score = ($profile->mealRecipeScores[$meal][$recipeId] ?? 0.0) * 2.0
            + ($profile->recipeScores[$recipeId] ?? 0.0);
        $score += ($profile->cuisineScores[$recipe->cuisine->value] ?? 0.0);
        $tagScore = 0.0;

        foreach ($recipe->diet_tags ?? [] as $tag) {
            $tagScore += $profile->tagScores[$tag] ?? 0.0;
        }

        $score += min(1.0, $tagScore * 0.5);
        $ingredientScore = 0.0;

        foreach ($recipe->ingredients as $ingredient) {
            $ingredientScore += $profile->ingredientScores[(int) $ingredient->product_id] ?? 0.0;
        }

        $score += min(1.5, $ingredientScore * 0.3);

        if ($profile->averagePrepMinutes !== null && $recipe->prep_minutes !== null
            && abs($recipe->prep_minutes - $profile->averagePrepMinutes) <= 10) {
            $score += 0.5;
        }

        return max(-2.5, min(4.0, $score));
    }

    private function nutritionOf(Recipe $recipe): Nutrition
    {
        $recipeId = (int) $recipe->id;

        return $this->nutritionByRecipe[$recipeId] ??= $this->nutritionCalculator->perServing($recipe);
    }

    private function nutritionScore(Recipe $recipe, string $meal, MealPlanSettings $settings): float
    {
        $nutrition = $this->nutritionOf($recipe);

        if (! $nutrition->isComplete()) {
            return 0.0;
        }

        $score = 0.0;
        $target = $settings->daily_calorie_target;
        $meals = max(1, count($settings->meal_types));
        $share = $target !== null ? $target / $meals : null;

        if ($share !== null) {
            $delta = $nutrition->kcal - $share;
            $score += $delta > 0 ? -min(6.0, $delta / 80.0) : 1.5;
        }

        $limit = $settings->dinner_carb_limit_g;

        if ($meal === 'dinner' && $limit !== null) {
            $score += $nutrition->carbsG <= $limit
                ? 5.0
                : -min(10.0, ($nutrition->carbsG - $limit) / 5.0);
        }

        $profile = $settings->diet_profile ?? DietProfile::Balanced;

        if ($profile === DietProfile::HighProtein) {
            $score += min(3.0, $nutrition->proteinG / 15.0);
        }

        if ($profile === DietProfile::Light && $share !== null && $nutrition->kcal <= $share) {
            $score += 2.0;
        }

        return $score;
    }

    /**
     * @param  array<int, Recipe>  $candidates
     * @param  array<int, int>  $usage
     * @param  array<string, int>  $slotRecipe
     */
    private function hasLowCarbAlternative(
        array $candidates,
        int $exceptId,
        string $date,
        string $meal,
        array $usage,
        array $slotRecipe,
        int $limit,
    ): bool {
        foreach ($candidates as $recipe) {
            $recipeId = (int) $recipe->id;

            if ($recipe->course === RecipeCourse::Drink
                || $recipeId === $exceptId
                || ! $this->canUseRecipe($recipeId, $date, $meal, $usage, $slotRecipe)) {
                continue;
            }

            $nutrition = $this->nutritionOf($recipe);

            if ($nutrition->isComplete() && $nutrition->carbsG <= $limit) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, Recipe>  $candidates
     * @param  array<int, int>  $priorityOrder
     * @param  array<int, array{quantity: float, unit: Unit, expiresSoon: bool}>  $available
     * @param  array<int, int>  $usage
     * @param  array<string, int>  $slotRecipe
     */
    private function priorityProductFor(
        array $candidates,
        array $priorityOrder,
        array $available,
        string $date,
        string $meal,
        array $usage,
        array $slotRecipe,
    ): ?int {
        foreach ($priorityOrder as $productId) {
            if (($available[$productId]['quantity'] ?? 0.0) <= 0.0) {
                continue;
            }

            foreach ($candidates as $recipe) {
                if ($recipe->course === RecipeCourse::Drink
                    || ! $this->canUseRecipe((int) $recipe->id, $date, $meal, $usage, $slotRecipe)) {
                    continue;
                }

                if ($this->recipeUsesProduct($recipe, $productId)) {
                    return $productId;
                }
            }
        }

        return null;
    }

    private function recipeUsesProduct(Recipe $recipe, int $productId): bool
    {
        foreach ($recipe->ingredients as $ingredient) {
            if ((int) $ingredient->product_id === $productId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Productos que ya se están usando en otra comida del mismo día. Se usan
     * para preferir recetas que compartan una base y cocinarla una sola vez.
     *
     * @param  array<string, \App\Models\MealPlanEntry>  $created
     * @return array<int, true>
     */
    private function sharedProductIdsForDate(array $created, string $date): array
    {
        $productIds = [];

        foreach ($created as $key => $entry) {
            if (! str_starts_with($key, $date.'|') || $entry->kind !== MealEntryKind::Recipe) {
                continue;
            }

            if ($entry->recipe !== null) {
                foreach (array_keys($this->requirementsFor($entry->recipe, (int) $entry->cook_servings)) as $productId) {
                    $productIds[$productId] = true;
                }
            }

            if ($entry->drinkRecipe !== null) {
                foreach (array_keys($this->requirementsFor($entry->drinkRecipe, (int) $entry->servings)) as $productId) {
                    $productIds[$productId] = true;
                }
            }
        }

        return $productIds;
    }

    /**
     * @param  array<int, Recipe>  $candidates
     * @param  array<int, int>  $usage
     * @param  array<string, int>  $slotRecipe
     */
    private function hasDinnerCalorieAlternative(
        array $candidates,
        int $exceptId,
        string $date,
        string $meal,
        array $usage,
        array $slotRecipe,
        int $limit,
    ): bool {
        foreach ($candidates as $recipe) {
            $recipeId = (int) $recipe->id;

            if ($recipe->course === RecipeCourse::Drink
                || $recipeId === $exceptId
                || ! $this->canUseRecipe($recipeId, $date, $meal, $usage, $slotRecipe)) {
                continue;
            }

            $nutrition = $this->nutritionOf($recipe);

            if ($nutrition->isComplete() && $nutrition->kcal <= $limit) {
                return true;
            }
        }

        return false;
    }

    private function isSafeLeftover(Recipe $recipe): bool
    {
        if (! in_array('light', $recipe->diet_tags ?? [], true)) {
            return false;
        }

        $unsafe = config('shopping.leftover_unsafe_slugs', []);

        foreach ($recipe->ingredients as $ingredient) {
            $slug = $ingredient->product->slug;

            if ($slug !== null && in_array($slug, $unsafe, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, Recipe>  $candidates
     * @param  array<int, array{quantity: float, unit: Unit, expiresSoon: bool}>  $available
     * @param  array<int, int>  $usage
     * @param  array<string, int>  $slotRecipe
     */
    private function hasCheaperAlternative(
        array $candidates,
        int $exceptId,
        ?int $costCents,
        int $cookServings,
        array $available,
        string $date,
        string $meal,
        array $usage,
        array $slotRecipe,
    ): bool {
        foreach ($candidates as $recipe) {
            $recipeId = (int) $recipe->id;

            if ($recipeId === $exceptId
                || $recipe->course === RecipeCourse::Drink
                || ! $this->canUseRecipe($recipeId, $date, $meal, $usage, $slotRecipe)) {
                continue;
            }

            $alternative = $this->evaluate($recipe, $cookServings, $available)['costo_centavos'];

            if ($alternative === null) {
                continue;
            }

            if ($costCents === null || $alternative + 100 < $costCents) {
                return true;
            }
        }

        return false;
    }

    /**
     * En modo ahorro, la cena es una sobra del almuerzo cuando el plato es
     * compatible con dinner y cabe en los límites de la cena. Así se cocina una
     * vez y no se compra ni se cocina un segundo plato.
     *
     * @param  array<int, string>  $days
     * @param  array<int, string>  $meals
     * @param  array<string, \App\Models\MealPlanEntry>  $created
     */
    private function applySaveMoneyLunchLeftovers(
        int $userId,
        MealPlanSettings $settings,
        array $days,
        array $meals,
        array &$created,
    ): void {
        if (($settings->menu_strategy ?? MenuStrategy::Balanced) !== MenuStrategy::SaveMoney
            || ! in_array('lunch', $meals, true)
            || ! in_array('dinner', $meals, true)) {
            return;
        }

        $household = (int) $settings->household_size;

        foreach ($days as $date) {
            $lunch = $created[$date.'|lunch'] ?? null;

            if ($lunch === null
                || $lunch->kind !== MealEntryKind::Recipe
                || $lunch->recipe === null
                || ! in_array('dinner', $lunch->recipe->meal_types, true)) {
                continue;
            }

            $dinner = $created[$date.'|dinner'] ?? null;

            if ($dinner !== null
                && in_array($dinner->kind, [MealEntryKind::EatOut, MealEntryKind::Free], true)) {
                continue;
            }

            $nutrition = $this->nutritionOf($lunch->recipe);

            if (! $this->isSafeLeftover($lunch->recipe)) {
                continue;
            }

            if ($nutrition->isComplete()) {
                $carbLimit = $settings->dinner_carb_limit_g;

                if ($carbLimit !== null && $nutrition->carbsG > $carbLimit) {
                    continue;
                }

                $calorieLimit = $settings->dinner_calorie_limit;

                if ($calorieLimit !== null && $nutrition->kcal > $calorieLimit) {
                    continue;
                }
            }

            foreach ($created as $key => $entry) {
                if ($entry->leftover_of_entry_id === $lunch->id
                    && $entry->plan_date->toDateString() !== $date) {
                    $this->mealPlanRepository->deleteEntry($entry);
                    unset($created[$key]);
                }
            }

            $cookServings = (int) $lunch->servings + $household;

            if ((int) $lunch->cook_servings !== $cookServings) {
                $lunch->update(['cook_servings' => $cookServings]);
                $created[$date.'|lunch'] = $lunch;
            }

            if ($dinner !== null
                && $dinner->kind === MealEntryKind::Leftover
                && $dinner->leftover_of_entry_id === $lunch->id) {
                continue;
            }

            $leftover = $this->mealPlanRepository->upsertEntry($userId, [
                'plan_date' => $date,
                'meal_type' => 'dinner',
                'kind' => MealEntryKind::Leftover->value,
                'recipe_id' => $lunch->recipe_id,
                'drink_recipe_id' => null,
                'servings' => $household,
                'cook_servings' => 0,
                'leftover_of_entry_id' => $lunch->id,
                'notes' => null,
            ]);

            $created[$date.'|dinner'] = $leftover;
        }
    }

    /**
     * @param  array<int, Recipe>  $candidates
     * @param  array<int, int>  $usage
     * @param  array<string, int>  $slotRecipe
     */
    private function hasDietAlternative(
        array $candidates,
        int $exceptId,
        string $tag,
        string $date,
        string $meal,
        array $usage,
        array $slotRecipe,
    ): bool {
        foreach ($candidates as $recipe) {
            $recipeId = (int) $recipe->id;

            if ($recipe->course === RecipeCourse::Drink
                || $recipeId === $exceptId
                || ! $this->canUseRecipe($recipeId, $date, $meal, $usage, $slotRecipe)) {
                continue;
            }

            if (in_array($tag, $recipe->diet_tags ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, Recipe>  $candidates
     * @param  array<int, int>  $usage
     * @param  array<string, int>  $slotRecipe
     */
    private function hasLessUsedAlternative(
        array $candidates,
        int $chosenId,
        string $date,
        string $meal,
        array $usage,
        array $slotRecipe,
    ): bool {
        $chosenUsage = $usage[$chosenId] ?? 0;

        foreach ($candidates as $recipe) {
            $recipeId = (int) $recipe->id;

            if ($recipe->course === RecipeCourse::Drink
                || $recipeId === $chosenId
                || ! $this->canUseRecipe($recipeId, $date, $meal, $usage, $slotRecipe)) {
                continue;
            }

            if (($usage[$recipeId] ?? 0) < $chosenUsage) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, int>  $usage
     * @param  array<string, int>  $slotRecipe
     */
    private function canUseRecipe(
        int $recipeId,
        string $date,
        string $meal,
        array $usage,
        array $slotRecipe,
    ): bool {
        if (($usage[$recipeId] ?? 0) >= self::MAX_RECIPE_OCCURRENCES) {
            return false;
        }

        $previous = CarbonImmutable::parse($date, 'America/Lima')->subDay()->toDateString();

        return ($slotRecipe[$previous.'|'.$meal] ?? null) !== $recipeId;
    }

    /**
     * @param  array<int, Recipe>  $candidates
     * @param  array<int, array{quantity: float, unit: Unit, expiresSoon: bool}>  $available
     * @param  array<int, int>  $usage
     * @param  array<string, int>  $slotRecipe
     */
    private function hasPantryAlternative(
        array $candidates,
        int $exceptId,
        array $available,
        array $usage,
        array $slotRecipe,
        string $date,
        string $meal,
        int $cookServings,
    ): bool {
        foreach ($candidates as $recipe) {
            $recipeId = (int) $recipe->id;

            if ($recipeId === $exceptId
                || ! $this->canUseRecipe($recipeId, $date, $meal, $usage, $slotRecipe)) {
                continue;
            }

            if ($this->evaluate($recipe, $cookServings, $available)['aprovechamiento'] > 0.0) {
                return true;
            }
        }

        return false;
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

            if (($settings->menu_strategy ?? MenuStrategy::Balanced) === MenuStrategy::SaveMoney
                && $meal === 'lunch'
                && in_array('dinner', $meals, true)
                && isset($created[$date.'|dinner'])
                && $created[$date.'|dinner']->kind === MealEntryKind::Leftover
                && $created[$date.'|dinner']->leftover_of_entry_id === $entry->id) {
                continue;
            }

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
     * @param  array<string, array<int, Recipe>>  $candidates
     */
    private function priceContextFor(array $candidates, CarbonImmutable $asOf): ?PriceContext
    {
        try {
            $productIds = [];

            foreach ($candidates as $recipes) {
                foreach ($recipes as $recipe) {
                    foreach ($recipe->ingredients as $ingredient) {
                        $productIds[(int) $ingredient->product_id] = true;
                    }
                }
            }

            if ($productIds === []) {
                return null;
            }

            $policies = $this->priceRegistry->enabledPolicies();

            return new PriceContext(
                quotes: $this->priceRepository->quotesFor(
                    array_keys($productIds),
                    $this->priceRegistry->cutoffsFor($asOf),
                ),
                policies: $policies,
            );
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
