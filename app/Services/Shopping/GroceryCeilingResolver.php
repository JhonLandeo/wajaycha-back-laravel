<?php

declare(strict_types=1);

namespace App\Services\Shopping;

use App\Actions\Shopping\ResolveGroceryCategoryAction;
use App\DTOs\Shopping\GroceryCeilingInput;
use App\Enums\BudgetPeriod;
use App\Repositories\Contracts\FoodBudgetRepositoryContract;
use App\Repositories\Contracts\GroceryBudgetRepositoryContract;
use Carbon\CarbonImmutable;

/**
 * Resolves the ceiling a list is compared against.
 *
 * The food budget is the current source (ADR-0013). The legacy grocery pin is
 * only a fallback for a user whose old pin has not been migrated yet; when the
 * food budget exists it always wins, even if a pin also exists.
 */
final class GroceryCeilingResolver
{
    public function __construct(
        private readonly FoodBudgetRepositoryContract $foodBudgetRepository,
        private readonly GroceryBudgetRepositoryContract $budgetRepository,
        private readonly ResolveGroceryCategoryAction $resolveCategoryAction,
    ) {}

    public function resolve(int $userId, CarbonImmutable $asOf): ?GroceryCeilingInput
    {
        $foodBudget = $this->foodBudgetRepository->snapshotFor($userId, $asOf);

        if ($foodBudget !== null) {
            return new GroceryCeilingInput(
                categoryId: $foodBudget->primaryCategoryId ?? 0,
                categoryName: $foodBudget->categoryName ?? 'Comida',
                monthlyBudget: $foodBudget->monthlyAmount,
                budgetPeriod: BudgetPeriod::MONTHLY,
                spent: $foodBudget->spent,
            );
        }

        $link = $this->resolveCategoryAction->execute($userId);

        return $link !== null ? $this->budgetRepository->ceilingFor($userId, $asOf) : null;
    }
}
