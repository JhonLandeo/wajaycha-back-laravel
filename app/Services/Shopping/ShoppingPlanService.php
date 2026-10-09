<?php

declare(strict_types=1);

namespace App\Services\Shopping;

use App\DTOs\Shopping\ConsumptionHabitLine;
use App\DTOs\Shopping\CoveredLine;
use App\DTOs\Shopping\GroceryCeilingInput;
use App\DTOs\Shopping\GroceryCeilingReading;
use App\DTOs\Shopping\PantryStock;
use App\DTOs\Shopping\PlanningWeek;
use App\DTOs\Shopping\ShoppingListLine;
use App\DTOs\Shopping\WeeklyShoppingPlan;
use App\Enums\BudgetPeriod;
use Carbon\CarbonImmutable;

/**
 * The weekly grocery plan, computed from values (design.md D1). The mapping
 * of {@see \App\Services\Pareto\ParetoReportBuilder} for Shopping: it opens
 * no connection, imports no `App\Models`, no `Illuminate\Http\Request`,
 * calls no `now()`, and runs no `DB::` query — `BoundariesTest.php` enforces
 * every one of those.
 *
 * `acquisition_source` never appears in this file. A gift subtracts from
 * availability exactly as a purchase does (design.md rule 6) — there is no
 * branch on the enum anywhere in the arithmetic, which is what makes "a gift
 * never touches a Transaction" structural rather than a rule to remember.
 */
final class ShoppingPlanService
{
    /**
     * `$lines`/`$covered` are derived from `(habits, pantry, week)` only,
     * and the ceiling reading from `(ceiling, week)` only — the two share
     * no term (design.md D5's q6 guarantee). An exceeded ceiling can
     * therefore never alter, reorder or hide a line.
     *
     * @param  ConsumptionHabitLine[]  $habits
     * @param  PantryStock[]  $pantry
     */
    public function plan(array $habits, array $pantry, ?GroceryCeilingInput $ceiling, PlanningWeek $week): WeeklyShoppingPlan
    {
        [$lines, $covered] = $this->buildLines($habits, $pantry, $week->asOf);

        usort($lines, static fn (ShoppingListLine $a, ShoppingListLine $b): int => $a->productName <=> $b->productName);

        [$ceilingState, $ceilingResolution, $ceilingReading] = $this->resolveCeiling($ceiling, $week->asOf);

        return new WeeklyShoppingPlan(
            week: $week,
            lines: $lines,
            covered: $covered,
            ceiling: $ceilingReading,
            ceilingState: $ceilingState,
            ceilingResolution: $ceilingResolution,
        );
    }

    /**
     * @param  ConsumptionHabitLine[]  $habits
     * @param  PantryStock[]  $pantry
     * @return array{0: array<int, ShoppingListLine>, 1: array<int, CoveredLine>}
     */
    private function buildLines(array $habits, array $pantry, CarbonImmutable $asOf): array
    {
        $pantryByProduct = [];
        foreach ($pantry as $stock) {
            $pantryByProduct[$stock->productId][] = $stock;
        }

        $lines = [];
        $covered = [];

        foreach ($habits as $habit) {
            $stocks = $pantryByProduct[$habit->productId] ?? [];

            $available = 0.0;
            $expired = 0.0;
            $unmatched = 0.0;
            $hasMismatch = false;
            $soonestExpiryOn = null;

            foreach ($stocks as $stock) {
                // Rule 4: a unit mismatch — including a null unit — is never
                // converted and never netted, regardless of expiry.
                if ($stock->unit !== $habit->unit) {
                    $unmatched += $stock->quantity;
                    $hasMismatch = true;

                    continue;
                }

                // Rule 3: expired stock is shown but subtracts nothing; the
                // row stays exactly as stored, neither deleted nor archived.
                if ($stock->expiresOn !== null && $stock->expiresOn->lt($asOf)) {
                    $expired += $stock->quantity;

                    continue;
                }

                $available += $stock->quantity;

                if ($stock->expiresOn !== null
                    && ($soonestExpiryOn === null || $stock->expiresOn->lt($soonestExpiryOn))) {
                    $soonestExpiryOn = $stock->expiresOn;
                }
            }

            $toBuy = round(max(0.0, $habit->weeklyQuantity - $available), 3);

            if ($toBuy > 0.0) {
                $lines[] = new ShoppingListLine(
                    productId: $habit->productId,
                    productName: $habit->productName,
                    unit: $habit->unit,
                    neededQuantity: $habit->weeklyQuantity,
                    availableQuantity: $available,
                    expiredQuantity: $expired,
                    unmatchedUnitQuantity: $unmatched,
                    toBuyQuantity: $toBuy,
                    hasUnitMismatch: $hasMismatch,
                    soonestExpiryOn: $soonestExpiryOn,
                );
            } else {
                $covered[] = new CoveredLine(
                    productId: $habit->productId,
                    productName: $habit->productName,
                    unit: $habit->unit,
                    neededQuantity: $habit->weeklyQuantity,
                    availableQuantity: $available,
                );
            }
        }

        return [$lines, $covered];
    }

    /**
     * The three ceiling states (design.md D5): `null` and a non-positive
     * budget both collapse to no `GroceryCeilingReading` — `amount` is
     * `null` in the response for both, never a silent zero.
     *
     * @return array{0: string, 1: ?string, 2: ?GroceryCeilingReading}
     */
    private function resolveCeiling(?GroceryCeilingInput $ceiling, CarbonImmutable $asOf): array
    {
        if ($ceiling === null) {
            return ['unlinked', 'name_not_found', null];
        }

        if ($ceiling->monthlyBudget <= 0.0) {
            return ['unbudgeted', null, null];
        }

        [$startsAt, $endsAt] = $this->windowFor($asOf, $ceiling->budgetPeriod);

        return ['set', null, new GroceryCeilingReading(
            categoryId: $ceiling->categoryId,
            categoryName: $ceiling->categoryName,
            amount: $ceiling->monthlyBudget,
            spent: $ceiling->spent,
            remaining: round($ceiling->monthlyBudget - $ceiling->spent, 2),
            isExceeded: $ceiling->spent > $ceiling->monthlyBudget,
            overspend: round(max(0.0, $ceiling->spent - $ceiling->monthlyBudget), 2),
            budgetPeriod: $ceiling->budgetPeriod,
            windowStartsAt: $startsAt,
            windowEndsAt: $endsAt,
        )];
    }

    /**
     * design.md D5's window table, mirrored here (not shared with
     * `GroceryBudgetRepository::windowFor()`) because this pure service may
     * not depend on a repository — it exists only to REPORT the window on
     * the output, not to query spend with it.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function windowFor(CarbonImmutable $asOf, BudgetPeriod $budgetPeriod): array
    {
        return match ($budgetPeriod) {
            BudgetPeriod::YEARLY => [$asOf->startOfYear(), $asOf->startOfYear()->addYear()],
            BudgetPeriod::MONTHLY => [$asOf->startOfMonth(), $asOf->startOfMonth()->addMonthNoOverflow()],
        };
    }
}
