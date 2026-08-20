<?php

declare(strict_types=1);

namespace App\DTOs\Pareto;

/**
 * One Pareto band as its card reads it.
 *
 * The card answers two different questions and this DTO keeps them apart, because
 * a yearly envelope does not break them the same way:
 *
 * - `$actualPercentage` is DISTRIBUTION — what share of the budget sits in this
 *   band. Envelopes count, weighed at a twelfth, so the comparison against the
 *   50/30/20 target compares like with like.
 *
 * - `$monthlyBudget`, `$spent`, `$availableBudget` and `$percentageSpent` are PACE.
 *   Envelopes are absent from every one of them. {@see \App\Enums\BudgetPeriod}
 *   settled that: an envelope is consumed in jumps, and averaging it against a
 *   month produces "you blew the budget" on a budget nobody blew.
 *
 * The envelopes still travel in `$categories`, each with its own year-window
 * consumption, because they still belong to the band — health is a need.
 *
 * `$totalIncome`, `$totalExpense` and `$totalBudgeted` describe the whole report,
 * not this band, and are repeated on every row. That is a duplication the payload
 * shape already carried for the first two; `$totalBudgeted` follows it rather than
 * introducing an envelope object, because the client reads the response as a bare
 * paginated list of bands and re-nesting it would break a deployed frontend.
 */
final class ParetoBandReport
{
    /**
     * @param  array<int, ParetoCategoryLine>  $categories
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly float $percentage,
        public readonly float $actualPercentage,
        public readonly float $monthlyBudget,
        public readonly float $spent,
        public readonly array $categories,
        public readonly float $totalIncome,
        public readonly float $totalExpense,
        /**
         * Every budgeted category of the user, banded or not, expressed in the
         * selected window.
         *
         * It is the figure `$actualPercentage` is a share OF, and publishing it is
         * the point: a band reading 57.97% is 57.97% of what the user typed, not of
         * what they earn. Against `$totalIncome` the two numbers finally answer
         * "how much of what comes in is spoken for", which a 50/30/20 split is a
         * split of and the report could not state until now.
         */
        public readonly float $totalBudgeted,
    ) {}

    public function availableBudget(): float
    {
        return round($this->monthlyBudget - $this->spent, 2);
    }

    public function percentageSpent(): float
    {
        if ($this->monthlyBudget <= 0.0) {
            return 0.0;
        }

        return round(($this->spent * 100) / $this->monthlyBudget, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'percentage' => $this->percentage,
            'actual_percentage' => $this->actualPercentage,
            'monthly_budget' => $this->monthlyBudget,
            'spent' => $this->spent,
            'available_budget' => $this->availableBudget(),
            'percentage_spent' => $this->percentageSpent(),
            'categories' => array_map(
                static fn (ParetoCategoryLine $line): array => $line->toArray(),
                $this->categories
            ),
            'total_income' => $this->totalIncome,
            'total_expense' => $this->totalExpense,
            'total_budgeted' => $this->totalBudgeted,
        ];
    }
}
