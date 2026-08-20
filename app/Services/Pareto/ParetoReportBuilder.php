<?php

declare(strict_types=1);

namespace App\Services\Pareto;

use App\DTOs\Pareto\BudgetedCategoryRow;
use App\DTOs\Pareto\ParetoBandReport;
use App\DTOs\Pareto\ParetoBandRow;
use App\DTOs\Pareto\ParetoCategoryLine;
use App\DTOs\Pareto\ParetoWindow;
use App\DTOs\Pareto\ParetoWindowTotals;

/**
 * The Pareto report, computed from values.
 *
 * This class exists because [ADR-0009](../../../docs/decisions/0009-coach-narrates-does-not-advise.md)
 * requires Financial Analysis rules to leave PostgreSQL. Until 2026-08-19 the whole
 * report was `get_pareto_monthly_report`, a plpgsql function that summed
 * `monthly_budget` flat. A stored procedure can return 82%; it cannot be handed a
 * fixed set of categories in a unit test and asked why.
 *
 * It opens no connection and holds no model. Everything it needs arrives as
 * arguments, which is what makes the two rules below assertable against a fixture
 * rather than against a seeded database:
 *
 * 1. A yearly envelope weighs a twelfth in the DISTRIBUTION figure.
 * 2. A yearly envelope is absent from the PACE figures entirely.
 */
final class ParetoReportBuilder
{
    /**
     * @param  array<int, ParetoBandRow>  $bands
     * @param  array<int, BudgetedCategoryRow>  $categories  every budgeted leaf of the
     *                                                       user, including the ones
     *                                                       outside any band — they
     *                                                       still weigh in the total
     *                                                       the shares are taken from.
     * @param  array<int, float>  $spentInWindow  category id => net spend in the
     *                                            selected month/year.
     * @param  array<int, float>  $spentInYear  category id => net spend in the selected
     *                                          year, ignoring the month. Only envelopes
     *                                          read it.
     * @return array<int, ParetoBandReport>
     */
    public function build(
        array $bands,
        array $categories,
        array $spentInWindow,
        array $spentInYear,
        ParetoWindow $window,
        ParetoWindowTotals $totals
    ): array {
        $totalWeight = array_sum(array_map(
            static fn (BudgetedCategoryRow $category): float => $category->monthlyWeight(),
            $categories
        ));

        $byBand = [];
        foreach ($categories as $category) {
            if ($category->bandId !== null) {
                $byBand[$category->bandId][] = $category;
            }
        }

        // Scaled by the window for the same reason `budgetFor()` scales a rhythm
        // category: `monthlyWeight()` speaks in months, `$totals->income` speaks in
        // whatever period was filtered, and the two are only comparable in one unit.
        // A yearly envelope keeps its twelfth here — this is the DISTRIBUTION figure,
        // and over twelve months the twelfths add back up to the whole envelope.
        $totalBudgeted = round($totalWeight * $window->budgetMonths, 2);

        return array_map(
            fn (ParetoBandRow $band): ParetoBandReport => $this->reportFor(
                $band,
                $byBand[$band->id] ?? [],
                $spentInWindow,
                $spentInYear,
                $window,
                $totals,
                $totalWeight,
                $totalBudgeted
            ),
            $bands
        );
    }

    /**
     * @param  array<int, BudgetedCategoryRow>  $categories
     * @param  array<int, float>  $spentInWindow
     * @param  array<int, float>  $spentInYear
     */
    private function reportFor(
        ParetoBandRow $band,
        array $categories,
        array $spentInWindow,
        array $spentInYear,
        ParetoWindow $window,
        ParetoWindowTotals $totals,
        float $totalWeight,
        float $totalBudgeted
    ): ParetoBandReport {
        $lines = $this->lines($categories, $spentInWindow, $spentInYear, $window);

        $rhythm = array_filter($lines, static fn (ParetoCategoryLine $l): bool => ! $l->isEnvelope());

        $weight = array_sum(array_map(
            static fn (BudgetedCategoryRow $c): float => $c->monthlyWeight(),
            $categories
        ));

        return new ParetoBandReport(
            id: $band->id,
            name: $band->name,
            percentage: $band->percentage,
            actualPercentage: $totalWeight > 0.0 ? round(($weight * 100) / $totalWeight, 2) : 0.0,
            // Rhythm only, on both sides of the bar.
            monthlyBudget: round(array_sum(array_map(
                static fn (ParetoCategoryLine $l): float => $l->budgetInWindow,
                $rhythm
            )), 2),
            spent: round(array_sum(array_map(
                static fn (ParetoCategoryLine $l): float => $l->spent,
                $rhythm
            )), 2),
            categories: $lines,
            totalIncome: $totals->income,
            totalExpense: $totals->expense,
            totalBudgeted: $totalBudgeted,
        );
    }

    /**
     * @param  array<int, BudgetedCategoryRow>  $categories
     * @param  array<int, float>  $spentInWindow
     * @param  array<int, float>  $spentInYear
     * @return array<int, ParetoCategoryLine>
     */
    private function lines(
        array $categories,
        array $spentInWindow,
        array $spentInYear,
        ParetoWindow $window
    ): array {
        $lines = array_map(
            static fn (BudgetedCategoryRow $category): ParetoCategoryLine => new ParetoCategoryLine(
                id: $category->id,
                name: $category->name,
                type: $category->type,
                monthlyBudget: $category->monthlyBudget,
                budgetPeriod: $category->budgetPeriod,
                budgetInWindow: round($window->budgetFor($category), 2),
                spent: round($category->isEnvelope()
                    ? ($spentInYear[$category->id] ?? 0.0)
                    : ($spentInWindow[$category->id] ?? 0.0), 2),
            ),
            $categories
        );

        // Rhythm first, envelopes last, each by size. It is the order the card draws
        // its two lists in, and deciding it here keeps the client from re-sorting.
        usort($lines, static function (ParetoCategoryLine $a, ParetoCategoryLine $b): int {
            return [$a->isEnvelope(), -$a->budgetInWindow] <=> [$b->isEnvelope(), -$b->budgetInWindow];
        });

        // No array_values(): usort() reindexes in place, so $lines is already a list.
        return $lines;
    }
}
