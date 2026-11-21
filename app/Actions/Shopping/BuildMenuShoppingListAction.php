<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\DTOs\Shopping\PlanningWeek;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use App\Services\Shopping\GroceryCeilingResolver;
use App\Services\Shopping\MenuNeedsBuilder;
use App\Services\Shopping\ShoppingPlanService;
use App\Services\Shopping\ShoppingPriceContextBuilder;
use Carbon\CarbonImmutable;

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
        private readonly MenuNeedsBuilder $menuNeeds,
        private readonly GroceryCeilingResolver $ceilingResolver,
        private readonly ShoppingPlanService $planService,
        private readonly ShoppingPriceContextBuilder $priceContextBuilder,
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
        $needs = $this->menuNeeds->build($entries, $settings->plan_weekdays, $settings->meal_types);
        $pantry = $this->shoppingRepository->pantryStockFor($userId);
        $ceiling = $this->ceilingResolver->resolve($userId, $asOf);

        $plan = $this->planService->plan(
            $needs,
            $pantry,
            $ceiling,
            $week,
            $this->priceContextBuilder->build($this->productIds($needs), $week),
        );

        return $plan->toArray();
    }

    /**
     * @param  array<int, \App\DTOs\Shopping\ConsumptionHabitLine>  $needs
     * @return array<int, int>
     */
    private function productIds(array $needs): array
    {
        return array_map(static fn (\App\DTOs\Shopping\ConsumptionHabitLine $need): int => $need->productId, $needs);
    }
}
