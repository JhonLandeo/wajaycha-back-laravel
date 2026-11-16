<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\DTOs\Shopping\PantryStock;
use App\DTOs\Shopping\PlanningWeek;
use App\Enums\DietProfile;
use App\Enums\MenuStrategy;
use App\Enums\RecipeCourse;
use App\Enums\RecipeCuisine;
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
    private const MAX_RECIPE_OCCURRENCES = 2;

    /** @var array<int, \App\Models\ConsumptionHabit> */
    private array $habits = [];

    /** @var array<int, int> */
    private array $habitProductIds = [];

    private const EXPIRING_SOON_DAYS = 4;

    public function __construct(
        private readonly MealPlanRepositoryContract $mealPlanRepository,
        private readonly ShoppingRepositoryContract $shoppingRepository,
        private readonly FoodBudgetRepositoryContract $foodBudgetRepository,
        private readonly MenuCandidateFilter $candidateFilter,
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
    public function preview(int $userId, string $weekStart, bool $includeNewRecipes = false): array
    {
        $asOf = CarbonImmutable::now();
        $week = PlanningWeek::forWeekStarting(
            CarbonImmutable::parse($weekStart, 'America/Lima'),
            $asOf,
        );

        DB::beginTransaction();

        try {
            $source = $this->execute($userId, $week->startsOn->toDateString(), $includeNewRecipes);
            $entries = $this->mealPlanRepository->entriesForWeek($userId, $week->startsOn, $week->endsOn);

            return ['source' => $source, 'week' => $week, 'entries' => $entries];
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @return 'ai'|'fallback'
     */
    public function execute(int $userId, string $weekStart, bool $includeNewRecipes = true): string
    {
        $asOf = CarbonImmutable::now();
        $week = PlanningWeek::forWeekStarting(CarbonImmutable::parse($weekStart, 'America/Lima'), $asOf);
        $settings = $this->mealPlanRepository->settingsFor($userId);
        $days = $this->calendar->upcomingActiveDays($week, $settings->plan_weekdays);

        if ($days === []) {
            return 'fallback';
        }

        // La generación de recetas nuevas es opt-in: suma una segunda llamada
        // de IA. Si falla, el plan sigue con el recetario actual.
        if ($includeNewRecipes) {
            try {
                $this->recipeGenerator->execute($userId, $week->startsOn->toDateString());
            } catch (Throwable $e) {
                report($e);
            }
        }

        $meals = $settings->meal_types;
        $candidates = $this->candidateFilter->forUser($userId, $settings);
        $pantry = $this->shoppingRepository->pantryStockFor($userId);
        $this->habits = $this->shoppingRepository->activeHabitsFor($userId);
        $this->habitProductIds = array_map(
            static fn ($habit): int => (int) $habit->product_id,
            $this->habits,
        );

        $available = $this->pantryAvailabilityByProduct($pantry, $asOf);
        $usage = [];
        $slotRecipe = [];

        $selected = $this->fromAi($week, $settings, $days, $meals, $candidates, $pantry, $available, $usage, $slotRecipe);
        $source = $selected === [] ? 'fallback' : 'ai';

        DB::transaction(function () use ($userId, $week, $settings, $days, $meals, $candidates, $selected, &$available, &$usage, &$slotRecipe): void {
            $this->mealPlanRepository->clearWeek($userId, $week->startsOn, $week->endsOn);

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

                    $pick = $this->fallbackChoice(
                        $candidates[$meal] ?? [],
                        $available,
                        $usage,
                        $slotRecipe,
                        $date,
                        $meal,
                        $settings,
                    );

                    if ($pick === null) {
                        continue;
                    }

                    $choice = $pick['recipe'];
                    $recipeId = (int) $choice->id;

                    $this->deplete($available, $pick['evaluation']['requirements']);
                    $usage[$recipeId] = ($usage[$recipeId] ?? 0) + 1;
                    $slotRecipe[$key] = $recipeId;

                    $drinkPick = $this->chooseDrink(
                        $recipeId,
                        $candidates[$meal] ?? [],
                        $available,
                        $usage,
                        $settings,
                    );

                    $drinkId = null;

                    if ($drinkPick !== null) {
                        $this->deplete($available, $drinkPick['evaluation']['requirements']);
                        $drinkId = (int) $drinkPick['recipe']->id;
                        $usage[$drinkId] = ($usage[$drinkId] ?? 0) + 1;
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
        });

        return $source;
    }

    /**
     * @param  array<int, string>  $days
     * @param  array<int, string>  $meals
     * @param  array<string, array<int, Recipe>>  $candidates
     * @param  array<int, PantryStock>  $pantry
     * @param  array<int, array{quantity: float, unit: Unit, expiresSoon: bool}>  $available
     * @param  array<int, int>  $usage
     * @param  array<string, int>  $slotRecipe
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
    ): array {
        try {
            $response = $this->gateway->generateJson([
                ['parts' => [['text' => $this->prompt($week, $settings, $days, $meals, $candidates, $pantry, $available)]]],
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
        $selected = [];
        foreach ($days as $date) {
            foreach ($meals as $meal) {
                $key = $date.'|'.$meal;
                $choice = $choices[$key] ?? null;

                if ($choice === null) {
                    continue;
                }

                $recipeId = $choice['recipe_id'];
                $recipe = $byMeal[$meal][$recipeId];

                if (! $this->canUseRecipe($recipeId, $date, $meal, $usage, $slotRecipe)
                    && $this->hasUsableAlternative($candidates[$meal] ?? [], $recipeId, $date, $meal, $usage, $slotRecipe)) {
                    continue;
                }

                if ($recipe->course === RecipeCourse::Drink) {
                    continue;
                }

                if ($this->hasLessUsedAlternative($candidates[$meal] ?? [], $recipeId, $date, $meal, $usage, $slotRecipe)) {
                    continue;
                }

                $dietTag = match ($profile) {
                    DietProfile::HighProtein => 'high_protein',
                    DietProfile::Light => 'light',
                    default => null,
                };

                if ($dietTag !== null
                    && ! in_array($dietTag, $recipe->diet_tags ?? [], true)
                    && $this->hasDietAlternative($candidates[$meal] ?? [], $recipeId, $dietTag, $date, $meal, $usage, $slotRecipe)) {
                    continue;
                }

                $evaluation = $this->evaluate($recipe, $choice['cook_servings'], $available);

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
     */
    private function prompt(
        PlanningWeek $week,
        MealPlanSettings $settings,
        array $days,
        array $meals,
        array $candidates,
        array $pantry,
        array $available,
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
                    'comidas' => $recipe->meal_types,
                    'tipo' => $recipe->course->value,
                    'cocina' => $recipe->cuisine->value,
                    'diet_tags' => $recipe->diet_tags ?? [],
                    'minutos' => $recipe->prep_minutes,
                    'despensa_cobertura' => $evaluation['cobertura'],
                    'despensa_aprovechamiento' => $evaluation['aprovechamiento'],
                    'vence_pronto' => $evaluation['vence_pronto'],
                    'faltantes' => $evaluation['faltantes'],
                ];
            }
        }

        $visiblePantry = array_values(array_filter(
            $pantry,
            static fn (PantryStock $item): bool => $item->unit !== null
                && $item->quantity > 0.0
                && ($item->expiresOn === null || $item->expiresOn->toDateString() >= $today),
        ));

        $context = [
            'semana' => ['inicio' => $week->startsOn->toDateString(), 'fin' => $week->endsOn->toDateString()],
            'personas' => (int) $settings->household_size,
            'comidas_activas' => $meals,
            'dias_activos' => $days,
            'criterio' => ($settings->menu_strategy ?? MenuStrategy::Balanced)->value,
            'perfil' => ($settings->diet_profile ?? DietProfile::Balanced)->value,
            'cocina_preferida' => RecipeCuisine::Peruvian->value,
            'presupuesto_semanal' => $budget?->weeklyTarget,
            'despensa' => array_map(static fn (PantryStock $item): array => [
                'producto' => $item->productName,
                'cantidad' => $item->quantity,
                'unidad' => $item->unit?->value,
                'vence' => $item->expiresOn?->toDateString(),
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
- Según `criterio`: `use_pantry` prioriza `despensa_aprovechamiento` y reparte la despensa entre recetas; no elijas una receta con aprovechamiento 0 si otra candidata usa despensa. `save_money` minimiza `faltantes`. `balanced` prioriza variedad y perfil, con la cobertura como desempate; no busca vaciar la despensa.
- Según `perfil`: `vegetarian` y `vegan` ya vienen filtrados por el backend; `high_protein` y `light` prefieren recetas con esa etiqueta en `diet_tags`.
- No repitas la misma receta más de dos veces en la semana ni en días consecutivos para la misma comida. Respeta el tiempo máximo y el presupuesto semanal si está disponible.
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
        $threshold = $local->addDays(self::EXPIRING_SOON_DAYS)->toDateString();
        $available = [];

        foreach ($pantry as $stock) {
            if ($stock->unit === null || $stock->quantity <= 0.0) {
                continue;
            }

            if ($stock->expiresOn !== null && $stock->expiresOn->toDateString() < $today) {
                continue;
            }

            $productId = $stock->productId;

            if (isset($available[$productId]) && $available[$productId]['unit'] !== $stock->unit) {
                continue;
            }

            $available[$productId] ??= ['quantity' => 0.0, 'unit' => $stock->unit, 'expiresSoon' => false];
            $available[$productId]['quantity'] = round($available[$productId]['quantity'] + $stock->quantity, 3);

            if ($stock->expiresOn !== null && $stock->expiresOn->toDateString() <= $threshold) {
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
     *     vence_pronto: bool
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

        return [
            'requirements' => $requirements,
            'cobertura' => $needed > 0.0 ? round(min(1.0, $covered / $needed), 3) : 0.0,
            'aprovechamiento' => round($aprovechamiento, 3),
            'faltantes' => array_values(array_unique($faltantes)),
            'vence_pronto' => $expiresSoon,
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
     * @return array{
     *     recipe: Recipe,
     *     evaluation: array{
     *         requirements: array<int, array{name: string, unit: Unit, quantity: float}>,
     *         cobertura: float,
     *         aprovechamiento: float,
     *         faltantes: array<int, string>,
     *         vence_pronto: bool
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

        return $this->chooseDrink($mainId, $candidates, $available, $usage, $settings);
    }

    /**
     * Una bebida para la comida, elegida con el mismo criterio que el plato
     * principal. No aplica la regla de días consecutivos: una bebida puede
     * repetirse más seguido que un plato.
     *
     * @param  array<int, Recipe>  $candidates
     * @param  array<int, array{quantity: float, unit: Unit, expiresSoon: bool}>  $available
     * @param  array<int, int>  $usage
     * @return array{
     *     recipe: Recipe,
     *     evaluation: array{
     *         requirements: array<int, array{name: string, unit: Unit, quantity: float}>,
     *         cobertura: float,
     *         aprovechamiento: float,
     *         faltantes: array<int, string>,
     *         vence_pronto: bool
     *     }
     * }|null
     */
    private function chooseDrink(
        int $mainId,
        array $candidates,
        array $available,
        array $usage,
        MealPlanSettings $settings,
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

        usort($evaluated, function (array $a, array $b) use ($strategy, $profile, $usage): int {
            $scoreA = $this->scoreRecipe($a['evaluation'], $strategy, $usage[(int) $a['recipe']->id] ?? 0, $a['recipe'], $profile);
            $scoreB = $this->scoreRecipe($b['evaluation'], $strategy, $usage[(int) $b['recipe']->id] ?? 0, $b['recipe'], $profile);

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
     * @return array{
     *     recipe: Recipe,
     *     evaluation: array{
     *         requirements: array<int, array{name: string, unit: Unit, quantity: float}>,
     *         cobertura: float,
     *         aprovechamiento: float,
     *         faltantes: array<int, string>,
     *         vence_pronto: bool
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

        if (in_array($profile, [DietProfile::HighProtein, DietProfile::Light], true)) {
            $dietTag = $profile === DietProfile::HighProtein ? 'high_protein' : 'light';
            $withTag = array_values(array_filter(
                $candidates,
                static fn (Recipe $recipe): bool => in_array($dietTag, $recipe->diet_tags ?? [], true),
            ));

            if ($withTag !== []) {
                $candidates = $withTag;
            }
        }

        $usable = array_values(array_filter(
            $candidates,
            fn (Recipe $recipe): bool => $this->canUseRecipe((int) $recipe->id, $date, $meal, $usage, $slotRecipe),
        ));

        // Con tres recetas de desayuno y siete días, el tope de dos se agota.
        // Cuando no queda ninguna que lo respete, se relaja para no dejar el
        // hueco vacío.
        if ($usable === []) {
            $usable = $candidates;
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

        usort($evaluated, function (array $a, array $b) use ($strategy, $profile, $usage): int {
            $scoreA = $this->scoreRecipe(
                $a['evaluation'],
                $strategy,
                $usage[(int) $a['recipe']->id] ?? 0,
                $a['recipe'],
                $profile,
            );
            $scoreB = $this->scoreRecipe(
                $b['evaluation'],
                $strategy,
                $usage[(int) $b['recipe']->id] ?? 0,
                $b['recipe'],
                $profile,
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
     *     vence_pronto: bool
     * }  $evaluation
     */
    private function scoreRecipe(
        array $evaluation,
        MenuStrategy $strategy,
        int $occurrences,
        Recipe $recipe,
        DietProfile $profile,
    ): float {
        $varietyPenalty = $occurrences * 6.0;
        $dietBonus = match ($profile) {
            DietProfile::HighProtein => in_array('high_protein', $recipe->diet_tags ?? [], true) ? 3.0 : 0.0,
            DietProfile::Light => in_array('light', $recipe->diet_tags ?? [], true) ? 3.0 : 0.0,
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

        return $dietBonus + $habitBonus + $cuisineBonus + match ($strategy) {
            MenuStrategy::UsePantry => $evaluation['aprovechamiento'] * 6.0
                + $evaluation['cobertura'] * 2.0
                + ($evaluation['vence_pronto'] ? 2.0 : 0.0)
                - $varietyPenalty,
            MenuStrategy::SaveMoney => $evaluation['cobertura'] * 8.0
                + ($evaluation['vence_pronto'] ? 1.0 : 0.0)
                - $varietyPenalty,
            MenuStrategy::Balanced => $evaluation['cobertura'] * 4.0
                + ($evaluation['vence_pronto'] ? 2.0 : 0.0)
                - $varietyPenalty,
        };
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
     * @param  array<int, int>  $usage
     * @param  array<string, int>  $slotRecipe
     */
    private function hasUsableAlternative(
        array $candidates,
        int $exceptId,
        string $date,
        string $meal,
        array $usage,
        array $slotRecipe,
    ): bool {
        foreach ($candidates as $recipe) {
            $recipeId = (int) $recipe->id;

            if ($recipeId === $exceptId) {
                continue;
            }

            if ($this->canUseRecipe($recipeId, $date, $meal, $usage, $slotRecipe)) {
                return true;
            }
        }

        return false;
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
