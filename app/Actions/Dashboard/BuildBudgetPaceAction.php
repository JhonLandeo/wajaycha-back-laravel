<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\DTOs\Dashboard\BudgetPace;
use App\DTOs\Pareto\BudgetedCategoryRow;
use App\Enums\BudgetPeriod;
use App\Repositories\Contracts\DashboardRepositoryContract;
use App\Repositories\Contracts\ParetoRepositoryContract;
use App\Services\Dashboard\BudgetPaceBuilder;
use Carbon\CarbonImmutable;

/**
 * Assembles the month's budget pace: which categories count, what they are worth,
 * what has been spent day by day, and what the calendar makes of it.
 *
 * It reads through the two ports and decides through {@see BudgetPaceBuilder}.
 * The budget itself is not queried again: `budgetedLeafCategories()` already knows
 * that a parent category carries no budget and a yearly envelope is not divisible
 * into days, so re-deriving those rules here would give the dashboard a second,
 * quietly divergent definition of "budget".
 *
 * Only `monthly` budgets are paced. An envelope is consumed in jumps, and a
 * straight line across the month would report a medical bill as an overrun every
 * time it lands — the reason `BudgetPeriod` exists.
 */
final class BuildBudgetPaceAction
{
    public function __construct(
        private readonly DashboardRepositoryContract $dashboard,
        private readonly ParetoRepositoryContract $pareto,
        private readonly BudgetPaceBuilder $builder,
    ) {}

    public function execute(int $userId, int $year, int $month): BudgetPace
    {
        $timezone = (string) config('app.timezone');
        $now = CarbonImmutable::now($timezone);
        $periodStart = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $timezone);

        $daysInMonth = (int) $periodStart->daysInMonth;
        $isCurrentMonth = $periodStart->year === $now->year && $periodStart->month === $now->month;
        $isCompleteMonth = $periodStart->lessThan($now->startOfMonth());
        $daysElapsed = $isCurrentMonth
            ? $now->day
            : ($isCompleteMonth ? $daysInMonth : 0);

        $monthly = array_values(array_filter(
            $this->pareto->budgetedLeafCategories($userId),
            static fn (BudgetedCategoryRow $category): bool => $category->type === 'expense'
                && $category->budgetPeriod === BudgetPeriod::MONTHLY
                && $category->monthlyBudget > 0.0
        ));

        $monthlyBudget = round(array_sum(array_map(
            static fn (BudgetedCategoryRow $category): float => $category->monthlyBudget,
            $monthly
        )), 2);

        $categoryIds = array_map(
            static fn (BudgetedCategoryRow $category): int => $category->id,
            $monthly
        );

        return $this->builder->build(
            year: $year,
            month: $month,
            dailyExpense: $this->dashboard->dailyExpenseForCategories($userId, $year, $month, $categoryIds),
            monthlyBudget: $monthlyBudget,
            categoryCount: count($monthly),
            daysInMonth: $daysInMonth,
            daysElapsed: $daysElapsed,
            isCurrentMonth: $isCurrentMonth,
            isCompleteMonth: $isCompleteMonth,
            minDayForProjection: (int) config('coaching.min_day_for_projection', 5),
            overrunMargin: (float) config('coaching.overrun_margin', 0.10),
        );
    }
}
