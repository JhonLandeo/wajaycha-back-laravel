<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\DTOs\Shopping\PlanningWeek;
use App\Repositories\Contracts\GroceryBudgetRepositoryContract;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use App\Services\Shopping\ShoppingPlanService;
use Illuminate\Support\Carbon;

/**
 * Wires the weekly plan (design.md "Data flow"). Reads, writes and wires —
 * decides nothing itself, exactly the `BuildParetoReportAction` shape.
 *
 * Builds `PlanningWeek::forDate(Carbon::now())` here rather than inside
 * `ShoppingPlanService`, which may not call `now()` (design.md D1).
 */
final class BuildWeeklyPlanAction
{
    public function __construct(
        private readonly ShoppingRepositoryContract $shoppingRepository,
        private readonly GroceryBudgetRepositoryContract $budgetRepository,
        private readonly ResolveGroceryCategoryAction $resolveCategoryAction,
        private readonly ShoppingPlanService $planService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(int $userId): array
    {
        $week = PlanningWeek::forDate(Carbon::now()->toImmutable());

        $habits = $this->shoppingRepository->activeHabitLinesFor($userId);
        $pantry = $this->shoppingRepository->pantryStockFor($userId);

        // Deliberate side effect of a GET (design.md D6): this may INSERT a
        // grocery_budget_links row when no pin exists yet and the
        // configured category name resolves — the same lazy-resolve-then-
        // pin write `GroceryBudgetLinkController@show` already performs.
        $link = $this->resolveCategoryAction->execute($userId);
        $ceiling = $link !== null ? $this->budgetRepository->ceilingFor($userId, $week->asOf) : null;

        $plan = $this->planService->plan($habits, $pantry, $ceiling, $week);

        return $plan->toArray();
    }
}
