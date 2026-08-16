<?php

declare(strict_types=1);

namespace App\Services\Coaching;

use App\DTOs\Coaching\SpendingHabit;
use App\Enums\SpendingRhythm;

/**
 * Decides which categories arrive and which ones the user chooses, from how much
 * each one moves across complete months.
 *
 * The measure is the coefficient of variation — standard deviation over the mean —
 * and it is a ratio because the comparison has to hold across scales. A S/ 1200
 * rent and a S/ 60 subscription are both steady; a raw spread would call the rent
 * ten times more variable than the subscription and be describing nothing but the
 * size of the bill.
 *
 * Population standard deviation, not sample: these are all the months in the
 * window, not a draw from a larger set, and dividing by `n - 1` would also
 * explode on a single-month window.
 *
 * Pure, like every decider here, so the statistics can be asserted against fixed
 * series rather than a seeded database.
 */
final class SpendingRhythmClassifier
{
    /**
     * @param  array<int, array<int, object{category_id: int|null, category_name: string|null, total: float}>>  $months
     *                                                                                                                   one entry per complete month, oldest first — the shape
     *                                                                                                                   `TransactionRepositoryContract::expenseByCategoryBetween()`
     *                                                                                                                   returns, called once per month.
     * @param  float  $variationThreshold  at or below this, a category counts as
     *                                     fixed.
     * @param  int  $minMonthsOfHistory  months a category has to have existed for
     *                                   before any verdict is allowed.
     * @return SpendingHabit[] ordered by monthly average, largest first
     */
    public function classify(array $months, float $variationThreshold, int $minMonthsOfHistory): array
    {
        $monthCount = count($months);

        if ($monthCount === 0) {
            return [];
        }

        $series = $this->seriesByKey($months, $monthCount);
        $names = $this->namesByKey($months);

        $habits = [];

        foreach ($series as $key => $totals) {
            // Se mide desde que la categoria EXISTE, no desde el borde de la
            // ventana. Los meses anteriores al primer gasto no son meses en que el
            // usuario eligio no gastar: son meses en que la categoria no estaba, y
            // contarlos como ceros fabrica una variacion enorme que convierte
            // cualquier alquiler nuevo en un gasto discrecional.
            $lived = $this->sinceFirstSpend($totals);
            $history = count($lived);
            $average = $history === 0 ? 0.0 : array_sum($lived) / $history;
            $variation = $this->variation($lived, $average);

            $habits[] = new SpendingHabit(
                categoryId: $key === '' ? null : (int) $key,
                name: $names[$key] ?? 'Sin categoría',
                monthlyAverage: $average,
                variation: $variation,
                monthsOfHistory: $history,
                rhythm: $this->rhythmFor($variation, $history, $variationThreshold, $minMonthsOfHistory),
            );
        }

        usort($habits, fn (SpendingHabit $a, SpendingHabit $b): int => $b->monthlyAverage <=> $a->monthlyAverage);

        return $habits;
    }

    private function rhythmFor(float $variation, int $monthsOfHistory, float $threshold, int $minMonths): SpendingRhythm
    {
        if ($monthsOfHistory < $minMonths) {
            return SpendingRhythm::TOO_NEW;
        }

        return $variation <= $threshold ? SpendingRhythm::FIXED : SpendingRhythm::DISCRETIONARY;
    }

    /**
     * The series from its first month with spend onward — the months this category
     * has actually lived through.
     *
     * Its LENGTH is deliberately not the number of months that had spend, and the
     * difference is the whole point. A gift budget touched twice in six months has
     * six months of history and is genuinely erratic, which is the right verdict.
     * A rent first paid two months ago also shows two months with spend, and
     * nothing at all is known about it yet. Counting occurrences would collapse
     * those two into the same number and file the rent under choice.
     *
     * Interior zeros stay: a month in the middle where nothing was spent is a real
     * decision and is exactly what makes an occasional expense read as erratic.
     * Only the leading ones go, because those are not decisions at all.
     *
     * @param  float[]  $totals  oldest first
     * @return float[]
     */
    private function sinceFirstSpend(array $totals): array
    {
        foreach (array_values($totals) as $index => $total) {
            if ($total > 0.0) {
                return array_slice($totals, $index);
            }
        }

        return [];
    }

    /**
     * Coefficient of variation, or zero when there is nothing to divide.
     *
     * A mean of zero means the category had no spend in the window at all. It is
     * reported as perfectly steady and reaches the caller with an average of zero,
     * which is true and which the composer drops for being nothing.
     *
     * @param  float[]  $totals
     */
    private function variation(array $totals, float $average): float
    {
        if ($average <= 0.0 || $totals === []) {
            return 0.0;
        }

        $variance = array_sum(array_map(
            fn (float $total): float => ($total - $average) ** 2,
            $totals,
        )) / count($totals);

        return sqrt($variance) / $average;
    }

    /**
     * One series per category, padded with zeros for the months it is missing from.
     *
     * The padding is not bookkeeping. A month without spend is a real zero in the
     * series, and it is what makes an occasional expense read as erratic instead
     * of as a small steady one — drop those months and a category bought twice for
     * S/ 300 each time looks like a perfectly fixed S/ 300 bill.
     *
     * @param  array<int, array<int, object{category_id: int|null, category_name: string|null, total: float}>>  $months
     * @return array<string, float[]>
     */
    private function seriesByKey(array $months, int $monthCount): array
    {
        $series = [];

        foreach (array_values($months) as $index => $rows) {
            foreach ($rows as $row) {
                $key = $this->keyFor($row);

                if (! isset($series[$key])) {
                    $series[$key] = array_fill(0, $monthCount, 0.0);
                }

                $series[$key][$index] = (float) $row->total;
            }
        }

        return $series;
    }

    /**
     * @param  array<int, array<int, object{category_id: int|null, category_name: string|null, total: float}>>  $months
     * @return array<string, string>
     */
    private function namesByKey(array $months): array
    {
        $names = [];

        foreach ($months as $rows) {
            foreach ($rows as $row) {
                if ($row->category_name !== null && $row->category_name !== '') {
                    $names[$this->keyFor($row)] = (string) $row->category_name;
                }
            }
        }

        return $names;
    }

    /** Uncategorised spending travels under `''`; `0` would collide with a real id. */
    private function keyFor(object $row): string
    {
        return $row->category_id === null ? '' : (string) $row->category_id;
    }
}
