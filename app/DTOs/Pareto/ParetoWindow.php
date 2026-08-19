<?php

declare(strict_types=1);

namespace App\DTOs\Pareto;

use Carbon\CarbonInterface;

/**
 * The period the report was asked about, and how many months of monthly budget
 * that period is worth.
 *
 * `$budgetMonths` exists because the filter can select a whole year while
 * `monthly_budget` still holds one month. Without it the report compared a year of
 * spending against a single month of budget and every band read at roughly 1200%
 * consumed — the "Todos los meses" defect.
 */
final class ParetoWindow
{
    private function __construct(
        public readonly ?int $month,
        public readonly ?int $year,
        public readonly int $budgetMonths,
    ) {}

    /**
     * @param  int  $monthsWithActivity  distinct months the user has movements in,
     *                                   the only honest denominator when neither a
     *                                   month nor a year was selected.
     */
    public static function forFilter(
        ?int $month,
        ?int $year,
        int $monthsWithActivity,
        CarbonInterface $today
    ): self {
        return new self($month, $year, self::monthsIn($month, $year, $monthsWithActivity, $today));
    }

    private static function monthsIn(
        ?int $month,
        ?int $year,
        int $monthsWithActivity,
        CarbonInterface $today
    ): int {
        if ($month !== null) {
            return 1;
        }

        if ($year === null) {
            return max($monthsWithActivity, 1);
        }

        // The running year counts only the months that have had a chance to be
        // spent. Charging twelve against eight months of spending would flatter
        // every band for the rest of the year.
        return $year === $today->year ? $today->month : 12;
    }

    /**
     * The budget a category contributes to this window.
     *
     * An envelope is not scaled: it already covers the year, and multiplying it by
     * the window would turn one envelope into twelve.
     */
    public function budgetFor(BudgetedCategoryRow $category): float
    {
        return $category->isEnvelope()
            ? $category->monthlyBudget
            : $category->monthlyBudget * $this->budgetMonths;
    }
}
