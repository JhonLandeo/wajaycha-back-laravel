<?php

declare(strict_types=1);

namespace App\DTOs\Pareto;

use App\Enums\BudgetPeriod;

/**
 * One budgeted leaf category as the Pareto report needs it, already detached from
 * Eloquent.
 *
 * "Leaf" is the report's own filter, not a property of the row: a category with
 * children carries no budget of its own, and summing both levels would count the
 * same money twice.
 */
final class BudgetedCategoryRow
{
    /**
     * @param  float  $monthlyBudget  the amount as stored, in whatever unit
     *                                `$budgetPeriod` names — annual when yearly.
     *                                The column name predates the distinction; the
     *                                debt is recorded in the migration that added
     *                                `budget_period`.
     * @param  int|null  $bandId  the Pareto classification it belongs to, or null
     *                            when it sits outside the Pareto reading.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $type,
        public readonly float $monthlyBudget,
        public readonly BudgetPeriod $budgetPeriod,
        public readonly ?int $bandId,
    ) {}

    /**
     * What this category is worth per month, in the one unit that lets two budgets
     * be compared: a yearly envelope of S/ 1200 weighs S/ 100.
     *
     * Only ever used for DISTRIBUTION. Never as a denominator for a month's
     * spending — see {@see \App\Enums\BudgetPeriod} for why a twelfth is the wrong
     * question to ask an envelope about pace.
     */
    public function monthlyWeight(): float
    {
        return $this->budgetPeriod === BudgetPeriod::YEARLY
            ? $this->monthlyBudget / 12
            : $this->monthlyBudget;
    }

    public function isEnvelope(): bool
    {
        return $this->budgetPeriod === BudgetPeriod::YEARLY;
    }
}
