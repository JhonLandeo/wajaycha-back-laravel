<?php

declare(strict_types=1);

namespace App\DTOs\Pareto;

use App\Enums\BudgetPeriod;

/**
 * One category inside a band's card.
 *
 * Carries two budget figures on purpose. `$monthlyBudget` is the RAW stored amount
 * and is what the SPA's inline editor writes back to `PUT /api/categories/{id}`;
 * scaling it would multiply the user's budget by the window size on every save.
 * `$budgetInWindow` is the same amount expressed in the selected period, and is the
 * only one comparable against `$spent`.
 */
final class ParetoCategoryLine
{
    /**
     * @param  float  $spent  measured over the month for a rhythm category and over
     *                        the YEAR for an envelope. Asking how much of a yearly
     *                        envelope is left is a question about the year; scoped
     *                        to March it would report every untouched envelope as
     *                        untouched until the one purchase lands.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $type,
        public readonly float $monthlyBudget,
        public readonly BudgetPeriod $budgetPeriod,
        public readonly float $budgetInWindow,
        public readonly float $spent,
    ) {}

    public function isEnvelope(): bool
    {
        return $this->budgetPeriod === BudgetPeriod::YEARLY;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'monthly_budget' => $this->monthlyBudget,
            'budget_period' => $this->budgetPeriod->value,
            'budget_in_window' => $this->budgetInWindow,
            'spent' => $this->spent,
            'type' => $this->type,
        ];
    }
}
