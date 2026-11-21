<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\DTOs\Shopping\ConsumptionHabitLine;
use App\DTOs\Shopping\PlanningWeek;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use App\Services\Shopping\GroceryCeilingResolver;
use App\Services\Shopping\ShoppingPlanService;
use App\Services\Shopping\ShoppingPriceContextBuilder;
use Illuminate\Support\Carbon;

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
        private readonly GroceryCeilingResolver $ceilingResolver,
        private readonly ShoppingPlanService $planService,
        private readonly ShoppingPriceContextBuilder $priceContextBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(int $userId): array
    {
        $week = PlanningWeek::forDate(Carbon::now()->toImmutable());

        $habits = $this->shoppingRepository->activeHabitLinesFor($userId);
        $pantry = $this->shoppingRepository->pantryStockFor($userId);
        $ceiling = $this->ceilingResolver->resolve($userId, $week->asOf);

        $plan = $this->planService->plan(
            $habits,
            $pantry,
            $ceiling,
            $week,
            $this->priceContextBuilder->build($this->productIds($habits), $week),
        );

        return $plan->toArray();
    }

    /**
     * @param  ConsumptionHabitLine[]  $habits
     * @return array<int, int>
     */
    private function productIds(array $habits): array
    {
        return array_map(static fn (ConsumptionHabitLine $habit): int => $habit->productId, $habits);
    }
}
