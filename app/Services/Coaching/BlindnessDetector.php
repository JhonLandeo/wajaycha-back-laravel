<?php

declare(strict_types=1);

namespace App\Services\Coaching;

use App\DTOs\Coaching\BlindnessObservation;
use App\DTOs\Coaching\CategoryMonthSnapshot;

/**
 * Reports what the coach cannot watch, instead of staying silent about it.
 *
 * A category with `monthly_budget = 0` is structurally unable to raise a pace or
 * level observation — `PaceEvaluator` refuses it outright (design.md §5.1: "leaf
 * expense categories with `monthly_budget > 0` and `spent > 0`"). Silence there
 * reads as "you're fine" when it actually means "I'm not looking" — the owner's
 * explicit decision behind task 4.5b/4.5c. This is deliberately its own service,
 * never a method on `PaceEvaluator`: design.md §5.1 marks `blind` as "not
 * comparable", so it must never be ranked against a pace band on the same ladder.
 *
 * Pure PHP: no facades, no database, no `now()` — the same promise `PaceEvaluator`
 * makes (design.md D1), for the same reason.
 */
final class BlindnessDetector
{
    /**
     * @param  CategoryMonthSnapshot[]  $snapshots  the same snapshots PaceEvaluator
     *                                              receives — expense-only budget
     *                                              positions for one user, one month
     * @return BlindnessObservation|null null when nobody spent in an unbudgeted
     *                                   category this month; otherwise exactly ONE
     *                                   observation naming every such category
     */
    public function detect(array $snapshots): ?BlindnessObservation
    {
        $blindCategories = array_values(array_filter(
            $snapshots,
            fn (CategoryMonthSnapshot $snapshot): bool => $snapshot->type === 'expense'
                && $snapshot->monthlyBudget <= 0.0
                && $snapshot->spent > 0.0
        ));

        if ($blindCategories === []) {
            return null;
        }

        return new BlindnessObservation(
            categoryNames: array_map(fn (CategoryMonthSnapshot $snapshot): string => $snapshot->name, $blindCategories),
            totalSpent: array_sum(array_map(fn (CategoryMonthSnapshot $snapshot): float => $snapshot->spent, $blindCategories)),
        );
    }
}
