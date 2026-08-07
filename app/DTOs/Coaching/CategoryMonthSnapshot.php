<?php

declare(strict_types=1);

namespace App\DTOs\Coaching;

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
 */
final class CategoryMonthSnapshot
{
    public function __construct(
        public readonly int $categoryId,
        public readonly string $name,
        public readonly string $type,
        public readonly float $monthlyBudget,
        public readonly float $spent,
        public readonly float $largestExpenseAmount,
    ) {}
}
