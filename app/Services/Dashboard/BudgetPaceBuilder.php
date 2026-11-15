<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\DTOs\Dashboard\BudgetPace;

/**
 * Turns a month's daily spending and a monthly budget into the pace figure the
 * dashboard draws.
 *
 * Pure, like the coaching deciders: no connection, no model, no now(). The
 * calendar arrives as `$daysInMonth` and `$daysElapsed`, the budget arrives as a
 * float, and this class only does the arithmetic — which is what lets the
 * projection and the status be asserted against fixed numbers.
 *
 * The projection is deliberately withheld before `$minDayForProjection`. On day
 * two, `spent × days / 2` is an extrapolation from one movement, not a rate; the
 * coaching subsystem already refuses to speak that early and this chart should
 * not say what the coach would not.
 */
final class BudgetPaceBuilder
{
    /**
     * @param  array<int, float>  $dailyExpense  day of month => expense amount
     */
    public function build(
        int $year,
        int $month,
        array $dailyExpense,
        float $monthlyBudget,
        int $categoryCount,
        int $daysInMonth,
        int $daysElapsed,
        bool $isCurrentMonth,
        bool $isCompleteMonth,
        int $minDayForProjection = 5,
        float $overrunMargin = 0.10,
    ): BudgetPace {
        $seriesDays = match (true) {
            $isCurrentMonth => min($daysElapsed, $daysInMonth),
            $isCompleteMonth => $daysInMonth,
            default => 0,
        };

        $daily = [];
        $spent = 0.0;

        for ($day = 1; $day <= $seriesDays; $day++) {
            $amount = round((float) ($dailyExpense[$day] ?? 0.0), 2);
            $spent += $amount;

            $daily[] = [
                'day' => $day,
                'amount' => $amount,
                'cumulative' => round($spent, 2),
            ];
        }

        $spent = round($spent, 2);
        $remaining = round($monthlyBudget - $spent, 2);
        $percentageUsed = $monthlyBudget > 0.0
            ? round(($spent * 100) / $monthlyBudget, 2)
            : 0.0;
        $paceBudgetToDate = $daysInMonth > 0
            ? round(($monthlyBudget * $daysElapsed) / $daysInMonth, 2)
            : 0.0;

        $projected = null;
        if ($isCurrentMonth && $daysInMonth > 0 && $daysElapsed >= $minDayForProjection) {
            $projected = round(($spent * $daysInMonth) / $daysElapsed, 2);
        }

        $status = match (true) {
            $monthlyBudget <= 0.0 => 'no_budget',
            $seriesDays <= 0 => 'no_data',
            $spent > $monthlyBudget => 'over',
            $projected !== null && $projected > $monthlyBudget * (1 + $overrunMargin) => 'watch',
            default => 'on_track',
        };

        return new BudgetPace(
            year: $year,
            month: $month,
            daysInMonth: $daysInMonth,
            daysElapsed: $daysElapsed,
            isCurrentMonth: $isCurrentMonth,
            isCompleteMonth: $isCompleteMonth,
            categoryCount: $categoryCount,
            monthlyBudget: round($monthlyBudget, 2),
            spent: $spent,
            remaining: $remaining,
            percentageUsed: $percentageUsed,
            paceBudgetToDate: $paceBudgetToDate,
            projected: $projected,
            status: $status,
            daily: $daily,
        );
    }
}
