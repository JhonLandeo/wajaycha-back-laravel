<?php

declare(strict_types=1);

namespace App\DTOs\Coaching;

use App\Enums\BudgetPeriod;

/**
 * One category's month-to-date position, as PaceEvaluator needs it.
 *
 * `type` and `largestExpenseAmount` are carried in addition to the four fields
 * design.md §9 names for this DTO. `type` lets the evaluator itself refuse to speak
 * about a non-expense category rather than trusting an upstream filter alone
 * (spec.md "Expense categories only"). `largestExpenseAmount` is required because
 * design.md §5.1's decision table (order 3) decides lumpiness *before* any
 * projection is computed — it cannot wait for the post-evaluation cause query
 * design.md D5 describes for message composition.
 *
 * `budgetPeriod` and `spentInYear` extend that table to budgets whose natural
 * unit is not the month. Both default so the ~dozen existing call sites that
 * build a monthly snapshot keep compiling and keep meaning what they meant.
 */
final class CategoryMonthSnapshot
{
    /**
     * @param  float  $monthlyBudget  the budget amount in whatever unit
     *                                `$budgetPeriod` names — annual when that is
     *                                'yearly'. The name is inherited from the
     *                                `categories.monthly_budget` column, whose
     *                                naming debt is recorded in the migration
     *                                that added `budget_period`.
     * @param  float  $spent  month-to-date, always — the denominator for
     *                        lumpiness and the numerator for a monthly
     *                        projection. Never the year's figure.
     * @param  float  $spentInYear  year-to-date in the reference timezone,
     *                              populated only for yearly categories. Zero
     *                              for monthly ones, which never read it.
     */
    public function __construct(
        public readonly int $categoryId,
        public readonly string $name,
        public readonly string $type,
        public readonly float $monthlyBudget,
        public readonly float $spent,
        public readonly float $largestExpenseAmount,
        public readonly BudgetPeriod $budgetPeriod = BudgetPeriod::MONTHLY,
        public readonly float $spentInYear = 0.0,
    ) {}
}
