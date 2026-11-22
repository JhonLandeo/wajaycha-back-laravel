<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\DTOs\Shopping\ConsumptionHabitLine;
use App\DTOs\Shopping\PlanningWeek;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use App\Repositories\Contracts\ShoppingRepositoryContract;
use App\Services\Shopping\GroceryCeilingResolver;
use App\Services\Shopping\MenuNeedsBuilder;
use App\Services\Shopping\ShoppingNeedsMerger;
use App\Services\Shopping\ShoppingPlanService;
use App\Services\Shopping\ShoppingPriceContextBuilder;
use Carbon\CarbonImmutable;

/**
 * One Compra: menu needs plus weekly habits minus the pantry, with the user's
 * check-off marks attached.
 *
 * The source filter is a real subset, not a label: `sources=menu` does not read
 * habits and `sources=habits` does not read the menu. With both, a product that
 * appears in the two sources is merged into one line before the pantry is
 * subtracted, so it is never bought twice (ADR-0048).
 */
final class BuildUnifiedShoppingListAction
{
    public function __construct(
        private readonly MealPlanRepositoryContract $mealPlanRepository,
        private readonly ShoppingRepositoryContract $shoppingRepository,
        private readonly MenuNeedsBuilder $menuNeeds,
        private readonly ShoppingNeedsMerger $merger,
        private readonly GroceryCeilingResolver $ceilingResolver,
        private readonly ShoppingPlanService $planService,
        private readonly ShoppingPriceContextBuilder $priceContextBuilder,
    ) {}

    /**
     * @param  array<int, string>  $sources  `menu`, `habits` or both.
     * @return array<string, mixed>
     */
    public function execute(int $userId, string $weekStart, array $sources): array
    {
        $asOf = CarbonImmutable::now();
        $week = PlanningWeek::forWeekStarting(
            CarbonImmutable::parse($weekStart, 'America/Lima'),
            $asOf
        );

        $lineSets = [];

        if (in_array('menu', $sources, true)) {
            $settings = $this->mealPlanRepository->settingsFor($userId);
            $entries = $this->mealPlanRepository->entriesForWeek($userId, $week->startsOn, $week->endsOn);
            $lineSets[] = $this->menuNeeds->build(
                $entries,
                $settings->plan_weekdays,
                $settings->meal_types,
                $week->shoppingFrom(),
            );
        }

        if (in_array('habits', $sources, true)) {
            $lineSets[] = $this->shoppingRepository->activeHabitLinesFor($userId);
        }

        $needs = $this->merger->merge(...$lineSets);
        $pantry = $this->shoppingRepository->pantryStockFor($userId);
        $ceiling = $this->ceilingResolver->resolve($userId, $asOf);

        $plan = $this->planService->plan(
            $needs,
            $pantry,
            $ceiling,
            $week,
            $this->priceContextBuilder->build($this->productIds($needs), $week),
        );

        $checked = $this->shoppingRepository->checkedProductIdsFor(
            $userId,
            $week->startsOn->toDateString(),
        );

        return $plan->withChecked($checked)->toArray();
    }

    /**
     * @param  array<int, ConsumptionHabitLine>  $needs
     * @return array<int, int>
     */
    private function productIds(array $needs): array
    {
        return array_map(static fn (ConsumptionHabitLine $need): int => $need->productId, $needs);
    }
}
