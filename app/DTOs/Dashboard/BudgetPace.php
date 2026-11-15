<?php

declare(strict_types=1);

namespace App\DTOs\Dashboard;

/**
 * How the month's spending is tracking against the budgets that can be paced.
 *
 * Only `monthly` budgets take part. A yearly envelope is consumed in jumps, so
 * neither a straight budget line nor a projection means anything for it — the
 * same rule {@see \App\Enums\BudgetPeriod} states and
 * {@see \App\Services\Coaching\PaceEvaluator} already honours. That is why the
 * `spent` figure here is the spend of the monthly-budgeted categories, not the
 * user's total expense: numerator and denominator share a scope.
 *
 * `$status` is one of:
 * - `no_budget` — the user has no monthly budget to pace.
 * - `no_data`   — the selected month has not started yet.
 * - `over`      — spend already passed the budget.
 * - `watch`     — projected month-end spend passes the budget plus the margin.
 * - `on_track`  — everything else.
 */
final class BudgetPace
{
    /**
     * @param  array<int, array{day: int, amount: float, cumulative: float}>  $daily
     *                                                                                one entry per elapsed day, cumulative included so the chart does not
     *                                                                                have to re-add the series on every render.
     */
    public function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly int $daysInMonth,
        public readonly int $daysElapsed,
        public readonly bool $isCurrentMonth,
        public readonly bool $isCompleteMonth,
        public readonly int $categoryCount,
        public readonly float $monthlyBudget,
        public readonly float $spent,
        public readonly float $remaining,
        public readonly float $percentageUsed,
        public readonly float $paceBudgetToDate,
        public readonly ?float $projected,
        public readonly string $status,
        public readonly array $daily,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'year' => $this->year,
            'month' => $this->month,
            'days_in_month' => $this->daysInMonth,
            'days_elapsed' => $this->daysElapsed,
            'is_current_month' => $this->isCurrentMonth,
            'is_complete_month' => $this->isCompleteMonth,
            'category_count' => $this->categoryCount,
            'monthly_budget' => $this->monthlyBudget,
            'spent' => $this->spent,
            'remaining' => $this->remaining,
            'percentage_used' => $this->percentageUsed,
            'pace_budget_to_date' => $this->paceBudgetToDate,
            'projected' => $this->projected,
            'status' => $this->status,
            'daily' => $this->daily,
        ];
    }
}
