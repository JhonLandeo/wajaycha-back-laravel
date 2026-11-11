<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\DTOs\Prices\PriceContext;
use App\DTOs\Shopping\ConsumptionHabitLine;
use App\DTOs\Shopping\GroceryCeilingInput;
use App\DTOs\Shopping\PlanningWeek;
use App\Enums\BudgetPeriod;
use App\Enums\MealEntryKind;
use App\Enums\Unit;
use App\Repositories\Contracts\FoodBudgetRepositoryContract;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use App\Repositories\Contracts\PriceRepositoryContract;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use App\Services\Prices\PriceSourceRegistry;
use App\Services\Shopping\ShoppingPlanService;
use App\Services\Shopping\UnitConverter;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Convierte el menú de una semana en la misma lista de compras que ya usa el
 * plan de hábitos. La diferencia es la fuente de las necesidades: recetas
 * escaladas por porciones cocinadas, no hábitos semanales.
 *
 * Una comida con `cook_servings > servings` deja sobras; sus ingredientes se
 * cuentan una sola vez. Las entradas `leftover`, `eat_out` y `free` no aportan
 * ingredientes.
 */
final class BuildMenuShoppingListAction
{
    public function __construct(
        private readonly MealPlanRepositoryContract $mealPlanRepository,
        private readonly ShoppingRepositoryContract $shoppingRepository,
        private readonly FoodBudgetRepositoryContract $foodBudgetRepository,
        private readonly ShoppingPlanService $planService,
        private readonly PriceRepositoryContract $priceRepository,
        private readonly PriceSourceRegistry $priceRegistry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(int $userId, string $weekStart): array
    {
        $asOf = CarbonImmutable::now();
        $week = PlanningWeek::forWeekStarting(
            CarbonImmutable::parse($weekStart, 'America/Lima'),
            $asOf
        );

        $settings = $this->mealPlanRepository->settingsFor($userId);
        $entries = $this->mealPlanRepository->entriesForWeek($userId, $week->startsOn, $week->endsOn);
        $needs = $this->needsFor($entries, $settings->plan_weekdays, $settings->meal_types);
        $pantry = $this->shoppingRepository->pantryStockFor($userId);
        $ceiling = $this->ceilingFor($userId, $asOf);

        $plan = $this->planService->plan(
            $needs,
            $pantry,
            $ceiling,
            $week,
            $this->priceContextFor($needs, $week),
        );

        return $plan->toArray();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, \App\Models\MealPlanEntry>  $entries
     * @param  array<int, int>  $planWeekdays
     * @param  array<int, string>  $mealTypes
     * @return array<int, ConsumptionHabitLine>
     */
    private function needsFor($entries, array $planWeekdays, array $mealTypes): array
    {
        $aggregated = [];

        foreach ($entries as $entry) {
            if (! in_array($entry->plan_date->isoWeekday(), $planWeekdays, true)) {
                continue;
            }
            if (! in_array($entry->meal_type->value, $mealTypes, true)) {
                continue;
            }
            if ($entry->kind !== MealEntryKind::Recipe || $entry->recipe === null) {
                continue;
            }

            $recipe = $entry->recipe;
            $scale = $entry->cook_servings / max(1, $recipe->base_servings);

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

    private function ceilingFor(int $userId, CarbonImmutable $asOf): ?GroceryCeilingInput
    {
        $snapshot = $this->foodBudgetRepository->snapshotFor($userId, $asOf);

        if ($snapshot === null) {
            return null;
        }

        return new GroceryCeilingInput(
            categoryId: $snapshot->primaryCategoryId ?? 0,
            categoryName: $snapshot->categoryName ?? 'Comida',
            monthlyBudget: $snapshot->monthlyAmount,
            budgetPeriod: BudgetPeriod::MONTHLY,
            spent: $snapshot->spent,
        );
    }

    /**
     * @param  array<int, ConsumptionHabitLine>  $needs
     */
    private function priceContextFor(array $needs, PlanningWeek $week): ?PriceContext
    {
        try {
            $policies = $this->priceRegistry->enabledPolicies();
            $productIds = array_values(array_unique(array_map(
                static fn (ConsumptionHabitLine $need): int => $need->productId,
                $needs,
            )));

            return new PriceContext(
                quotes: $this->priceRepository->quotesFor($productIds, $this->priceRegistry->cutoffsFor($week->asOf)),
                policies: $policies,
            );
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
