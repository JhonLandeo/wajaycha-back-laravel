<?php

declare(strict_types=1);

namespace App\Services\Coaching;

use App\DTOs\Coaching\CategoryMonthSnapshot;
use App\DTOs\Coaching\MonthCursor;
use App\DTOs\Coaching\PaceObservation;
use App\DTOs\Coaching\PaceThresholds;

/**
 * Decides, per expense category, whether the month's spending pace is worth
 * reporting — never whether or how to send it (design.md §1: "PaceEvaluator
 * decides nothing about delivery").
 *
 * Pure PHP: no facades, no Eloquent, no now(). Every input arrives as a value
 * object, which is what makes design.md D1's promise — "unit-tested with no
 * database" — mechanically true rather than aspirational.
 */
class PaceEvaluator
{
    /**
     * @param  CategoryMonthSnapshot[]  $snapshots
     * @return PaceObservation[] ordered by severity — over_budget first (descending
     *                           spent/budget), then projected_over (descending
     *                           projected/budget) — truncated to
     *                           $thresholds->maxObservations (design.md §5.1)
     */
    public function evaluate(array $snapshots, MonthCursor $cursor, PaceThresholds $thresholds): array
    {
        $observations = [];

        foreach ($snapshots as $snapshot) {
            $observation = $this->evaluateSnapshot($snapshot, $cursor, $thresholds);

            if ($observation !== null) {
                $observations[] = $observation;
            }
        }

        usort($observations, fn (PaceObservation $a, PaceObservation $b): int => $this->compareSeverity($a, $b));

        return array_slice($observations, 0, $thresholds->maxObservations);
    }

    /**
     * The decision table from design.md §5.1, in order:
     *   0. expenses only, budget > 0 and spent > 0 (blindness is the caller's concern)
     *   1. spent > budget                                          -> over_budget
     *   2. day < minDayForProjection                                -> silent
     *   3. largest single expense >= lumpyShare * spent              -> silent, no projection
     *   4. projected >= budget * (1 + overrunMargin)                 -> projected_over
     *   5. otherwise                                                 -> silent
     */
    private function evaluateSnapshot(
        CategoryMonthSnapshot $snapshot,
        MonthCursor $cursor,
        PaceThresholds $thresholds,
    ): ?PaceObservation {
        if ($snapshot->type !== 'expense') {
            return null;
        }

        if ($snapshot->monthlyBudget <= 0.0 || $snapshot->spent <= 0.0) {
            return null;
        }

        $isLumpy = $snapshot->largestExpenseAmount >= $thresholds->lumpyShare * $snapshot->spent;

        if ($snapshot->spent > $snapshot->monthlyBudget) {
            return $this->observation($snapshot, $cursor, band: 'over_budget', isLumpy: $isLumpy, projected: null);
        }

        if ($cursor->day < $thresholds->minDayForProjection) {
            return null;
        }

        if ($isLumpy) {
            return null;
        }

        $projected = $snapshot->spent * $cursor->daysInMonth / $cursor->day;

        if ($projected >= $snapshot->monthlyBudget * (1 + $thresholds->overrunMargin)) {
            return $this->observation($snapshot, $cursor, band: 'projected_over', isLumpy: false, projected: $projected);
        }

        return null;
    }

    private function observation(
        CategoryMonthSnapshot $snapshot,
        MonthCursor $cursor,
        string $band,
        bool $isLumpy,
        ?float $projected,
    ): PaceObservation {
        return new PaceObservation(
            subjectKey: "category:{$snapshot->categoryId}",
            categoryId: $snapshot->categoryId,
            name: $snapshot->name,
            band: $band,
            isLumpy: $isLumpy,
            spent: $snapshot->spent,
            budget: $snapshot->monthlyBudget,
            projected: $projected,
            dayOfMonth: $cursor->day,
        );
    }

    private function compareSeverity(PaceObservation $a, PaceObservation $b): int
    {
        $rankComparison = $this->severityRank($a) <=> $this->severityRank($b);

        if ($rankComparison !== 0) {
            return $rankComparison;
        }

        return $this->severityScore($b) <=> $this->severityScore($a);
    }

    private function severityRank(PaceObservation $observation): int
    {
        return match ($observation->band) {
            'over_budget' => 0,
            'projected_over' => 1,
            default => 2,
        };
    }

    private function severityScore(PaceObservation $observation): float
    {
        return match ($observation->band) {
            'over_budget' => $observation->spent / $observation->budget,
            'projected_over' => $observation->projected !== null ? $observation->projected / $observation->budget : 0.0,
            default => 0.0,
        };
    }
}
