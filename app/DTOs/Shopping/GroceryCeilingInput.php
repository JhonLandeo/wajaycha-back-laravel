<?php

declare(strict_types=1);

namespace App\DTOs\Shopping;

use App\Enums\BudgetPeriod;

/**
 * The pinned Category's budget position, already detached from Eloquent
 * (design.md "Inputs", D2). `GroceryBudgetRepository::ceilingFor()` composes
 * `CategoryRepositoryContract::findById()` and
 * `TransactionRepositoryContract::expenseByCategoryBetween()` into this DTO
 * before anything else sees either — `BoundariesTest.php` forbids `App\DTOs`
 * from importing `App\Models`.
 *
 * Carries no window and no ceiling-state string on purpose: computing the
 * date window from `BudgetPeriod` is the repository's job (design.md D5),
 * and `unlinked | unbudgeted | set` is a decision the caller makes from
 * this value's presence and `$monthlyBudget`'s sign — never baked in here,
 * so the same DTO answers both the slice-4 endpoint and, later,
 * `ShoppingPlanService`.
 *
 * `$spent` may legitimately be `0.0` — that is a real measurement, never
 * confused with the ceiling itself being absent (design.md D5).
 */
final class GroceryCeilingInput
{
    public function __construct(
        public readonly int $categoryId,
        public readonly string $categoryName,
        public readonly float $monthlyBudget,
        public readonly BudgetPeriod $budgetPeriod,
        public readonly float $spent,
    ) {}
}
