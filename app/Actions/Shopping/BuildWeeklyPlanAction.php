<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\DTOs\Prices\PriceContext;
use App\DTOs\Shopping\ConsumptionHabitLine;
use App\DTOs\Shopping\GroceryCeilingInput;
use App\DTOs\Shopping\PlanningWeek;
use App\Enums\BudgetPeriod;
use App\Repositories\Contracts\FoodBudgetRepositoryContract;
use App\Repositories\Contracts\GroceryBudgetRepositoryContract;
use App\Repositories\Contracts\PriceRepositoryContract;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use App\Services\Prices\PriceSourceRegistry;
use App\Services\Shopping\ShoppingPlanService;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Wires the weekly plan (design.md "Data flow"). Reads, writes and wires —
 * decides nothing itself, exactly the `BuildParetoReportAction` shape.
 *
 * Builds `PlanningWeek::forDate(Carbon::now())` here rather than inside
 * `ShoppingPlanService`, which may not call `now()` (design.md D1).
 *
 * Prices (grocery-prices): the quotes of the ENABLED sources for the products on
 * this user's lines are read here and handed to the pure service as a value.
 * Reading them can never fail the request — an exception is reported and the plan
 * is computed without a price context, which is the phase-1 shape plus null price
 * keys. No source is ever fetched from here; ingestion runs on the queue.
 */
final class BuildWeeklyPlanAction
{
    public function __construct(
        private readonly ShoppingRepositoryContract $shoppingRepository,
        private readonly FoodBudgetRepositoryContract $foodBudgetRepository,
        private readonly GroceryBudgetRepositoryContract $budgetRepository,
        private readonly ResolveGroceryCategoryAction $resolveCategoryAction,
        private readonly ShoppingPlanService $planService,
        private readonly PriceRepositoryContract $priceRepository,
        private readonly PriceSourceRegistry $priceRegistry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(int $userId): array
    {
        $week = PlanningWeek::forDate(Carbon::now()->toImmutable());

        $habits = $this->shoppingRepository->activeHabitLinesFor($userId);
        $pantry = $this->shoppingRepository->pantryStockFor($userId);

        $foodBudget = $this->foodBudgetRepository->snapshotFor($userId, $week->asOf);

        if ($foodBudget !== null) {
            $ceiling = new GroceryCeilingInput(
                categoryId: $foodBudget->primaryCategoryId ?? 0,
                categoryName: $foodBudget->categoryName ?? 'Comida',
                monthlyBudget: $foodBudget->monthlyAmount,
                budgetPeriod: BudgetPeriod::MONTHLY,
                spent: $foodBudget->spent,
            );
        } else {
            // Compatibilidad mientras el pin viejo no fue migrado: el nuevo
            // presupuesto de comida manda cuando existe; si no, se conserva el
            // comportamiento anterior.
            $link = $this->resolveCategoryAction->execute($userId);
            $ceiling = $link !== null ? $this->budgetRepository->ceilingFor($userId, $week->asOf) : null;
        }

        $plan = $this->planService->plan($habits, $pantry, $ceiling, $week, $this->priceContextFor($habits, $week));

        return $plan->toArray();
    }

    /**
     * Quotes for the user's products from the enabled sources, or null when the
     * read fails (reported to Sentry, never raised to the user).
     *
     * @param  ConsumptionHabitLine[]  $habits
     */
    private function priceContextFor(array $habits, PlanningWeek $week): ?PriceContext
    {
        try {
            $policies = $this->priceRegistry->enabledPolicies();
            $productIds = array_values(array_unique(array_map(
                static fn (ConsumptionHabitLine $habit): int => $habit->productId,
                $habits,
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
