<?php

declare(strict_types=1);

namespace App\Services\Shopping;

use App\DTOs\Prices\CeilingComparison;
use App\DTOs\Prices\LinePrice;
use App\DTOs\Prices\PlanEstimate;
use App\DTOs\Prices\PriceAlternative;
use App\DTOs\Prices\PriceContext;
use App\DTOs\Prices\ResolvedPrice;
use App\DTOs\Prices\ResolvedQuote;
use App\DTOs\Shopping\ConsumptionHabitLine;
use App\DTOs\Shopping\CoveredLine;
use App\DTOs\Shopping\GroceryCeilingInput;
use App\DTOs\Shopping\GroceryCeilingReading;
use App\DTOs\Shopping\PantryStock;
use App\DTOs\Shopping\PlanningWeek;
use App\DTOs\Shopping\ShoppingListLine;
use App\DTOs\Shopping\WeeklyShoppingPlan;
use App\Enums\BudgetPeriod;
use App\Services\Prices\AttributionFormatter;
use App\Services\Prices\CostCalculator;
use App\Services\Prices\PriceResolver;
use App\Services\Prices\WholesaleTrendCalculator;
use Carbon\CarbonImmutable;

/**
 * The weekly grocery plan, computed from values (design.md D1). The mapping
 * of {@see \App\Services\Pareto\ParetoReportBuilder} for Shopping: it opens
 * no connection, imports no `App\Models`, no `Illuminate\Http\Request`,
 * calls no `now()`, and runs no `DB::` query — `BoundariesTest.php` enforces
 * every one of those.
 *
 * Prices enter as an optional VALUE input (`PriceContext`) and are applied after
 * the list is built and sorted, by pure collaborators (resolver, trend, cost).
 * They annotate lines; they never add, drop, reorder or resize one (grocery-prices
 * spec, q6). The collaborators default to plain instances so a bare
 * `new ShoppingPlanService` still works — the container supplies a trend
 * calculator configured from `prices.trend`.
 *
 * `acquisition_source` never appears in this file. A gift subtracts from
 * availability exactly as a purchase does (design.md rule 6) — there is no
 * branch on the enum anywhere in the arithmetic, which is what makes "a gift
 * never touches a Transaction" structural rather than a rule to remember.
 */
final class ShoppingPlanService
{
    public function __construct(
        private readonly PriceResolver $resolver = new PriceResolver(new AttributionFormatter),
        private readonly WholesaleTrendCalculator $trend = new WholesaleTrendCalculator,
        private readonly CostCalculator $cost = new CostCalculator,
    ) {}

    /**
     * `$lines`/`$covered` are derived from `(habits, pantry, week)` only,
     * and the ceiling reading from `(ceiling, week)` only — the two share
     * no term (design.md D5's q6 guarantee). An exceeded ceiling can
     * therefore never alter, reorder or hide a line.
     *
     * @param  ConsumptionHabitLine[]  $habits
     * @param  PantryStock[]  $pantry
     */
    public function plan(array $habits, array $pantry, ?GroceryCeilingInput $ceiling, PlanningWeek $week, ?PriceContext $prices = null): WeeklyShoppingPlan
    {
        [$lines, $covered] = $this->buildLines($habits, $pantry, $week->asOf);

        usort($lines, static fn (ShoppingListLine $a, ShoppingListLine $b): int => $a->productName <=> $b->productName);

        [$ceilingState, $ceilingResolution, $ceilingReading] = $this->resolveCeiling($ceiling, $week->asOf);

        $estimate = null;
        if ($prices !== null) {
            [$lines, $lineCostsInCents] = $this->annotate($lines, $prices, $week->asOf);
            $estimate = $this->estimate($lineCostsInCents, $ceilingReading);
        }

        return new WeeklyShoppingPlan(
            week: $week,
            lines: $lines,
            covered: $covered,
            ceiling: $ceilingReading,
            ceilingState: $ceilingState,
            ceilingResolution: $ceilingResolution,
            estimate: $estimate,
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
     * Puts a price and a wholesale trend on every line, in place: same lines,
     * same order, same quantities. Returns the annotated lines and each line's
     * estimated cost in cents (null when the line is unknown), index-aligned.
     *
     * @param  array<int, ShoppingListLine>  $lines
     * @return array{0: array<int, ShoppingListLine>, 1: array<int, int|null>}
     */
    private function annotate(array $lines, PriceContext $prices, CarbonImmutable $asOf): array
    {
        $annotated = [];
        $costs = [];

        foreach ($lines as $line) {
            $resolved = $this->resolver->resolve($line->productId, $line->unit, $prices->quotes, $prices->policies, $asOf);
            $costInCents = $resolved->chosen !== null
                ? $this->cost->lineCostCents($line->toBuyQuantity, $resolved->chosen->quote->unitPrice)
                : null;

            $annotated[] = $line->withPrice(
                $this->linePrice($resolved, $costInCents),
                $this->trend->compute($line->productId, $prices->quotes, $prices->policies, $asOf),
            );
            $costs[] = $costInCents;
        }

        return [$annotated, $costs];
    }

    private function linePrice(ResolvedPrice $resolved, ?int $costInCents): LinePrice
    {
        $chosen = $resolved->chosen;
        if ($chosen === null || $costInCents === null || $chosen->quote->periodStart === null || $chosen->quote->periodEnd === null) {
            return LinePrice::unknown();
        }

        return new LinePrice(
            state: $resolved->state,
            unitPrice: $this->displayPrice($chosen),
            estimatedCost: $this->cost->centsToAmount($costInCents),
            basis: $chosen->quote->basis,
            source: $chosen->quote->source,
            sourceLabel: $chosen->sourceLabel,
            periodStart: $chosen->quote->periodStart,
            periodEnd: $chosen->quote->periodEnd,
            attribution: $chosen->attribution,
            alternatives: array_map(fn (ResolvedQuote $alternative): PriceAlternative => $this->alternative($alternative), $resolved->alternatives),
        );
    }

    private function alternative(ResolvedQuote $alternative): PriceAlternative
    {
        // The resolver only keeps dated quotes, so both periods are present.
        $quote = $alternative->quote;

        return new PriceAlternative(
            source: $quote->source,
            sourceLabel: $alternative->sourceLabel,
            unitPrice: $this->displayPrice($alternative),
            state: $alternative->state,
            basis: $quote->basis,
            periodStart: $quote->periodStart ?? $quote->periodEnd ?? throw new \LogicException('A resolved quote is always dated.'),
            periodEnd: $quote->periodEnd ?? throw new \LogicException('A resolved quote is always dated.'),
            attribution: $alternative->attribution,
        );
    }

    private function displayPrice(ResolvedQuote $resolved): float
    {
        return $this->cost->centsToAmount($this->cost->unitPriceToCents($resolved->quote->unitPrice));
    }

    /**
     * Sums in cents. Empty list: 0. Lines but none priced: null — never 0. The
     * ceiling comparison exists only for a `set` ceiling and a real total, and it
     * is returned even when the estimate is partial (a lower bound).
     *
     * @param  array<int, int|null>  $lineCostsInCents
     */
    private function estimate(array $lineCostsInCents, ?GroceryCeilingReading $ceilingReading): PlanEstimate
    {
        $priced = array_values(array_filter($lineCostsInCents, static fn (?int $cents): bool => $cents !== null));
        $pricedCount = count($priced);
        $unpricedCount = count($lineCostsInCents) - $pricedCount;

        $totalInCents = match (true) {
            $lineCostsInCents === [] => 0,
            $pricedCount === 0 => null,
            default => array_sum($priced),
        };

        $comparison = null;
        if ($ceilingReading !== null && $totalInCents !== null) {
            $remainingInCents = $this->cost->amountToCents($ceilingReading->remaining);
            $afterInCents = $remainingInCents - $totalInCents;

            $comparison = new CeilingComparison(
                remaining: $this->cost->centsToAmount($remainingInCents),
                remainingAfterEstimate: $this->cost->centsToAmount($afterInCents),
                wouldExceed: $afterInCents < 0,
            );
        }

        return new PlanEstimate(
            total: $totalInCents === null ? null : $this->cost->centsToAmount($totalInCents),
            pricedLines: $pricedCount,
            unpricedLines: $unpricedCount,
            isPartial: $unpricedCount > 0,
            ceilingComparison: $comparison,
        );
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
