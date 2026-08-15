<?php

declare(strict_types=1);

namespace App\Services\Coaching;

use App\DTOs\Coaching\CategoryMonthSnapshot;
use App\DTOs\Coaching\MonthCursor;
use App\DTOs\Coaching\PaceObservation;
use App\DTOs\Coaching\PaceThresholds;
use App\Enums\BudgetPeriod;

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
     *   0b. budget_period = yearly                                  -> {@see evaluateEnvelope()}
     *   1. spent > budget                                          -> over_budget
     *   2. day < minDayForProjection                                -> silent
     *   3. largest single expense >= lumpyShare * spent              -> silent, no projection
     *   4. projected >= budget * (1 + overrunMargin)                 -> projected_over
     *   5. otherwise                                                 -> silent
     *
     * Step 0b comes before step 1 deliberately, and it is the whole point of the
     * change. Step 1 asks "did you spend more this month than the budget?" — a
     * question with no meaning when the budget is a yearly envelope, and one that
     * used to fire on a single medical visit. Lumpiness could never prevent it:
     * it is only consulted at step 3, two steps too late.
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

        if ($snapshot->budgetPeriod === BudgetPeriod::YEARLY) {
            return $this->evaluateEnvelope($snapshot, $cursor, $thresholds);
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

    /**
     * The envelope table, for categories whose budget is yearly:
     *   1. spentInYear > budget                                      -> envelope_exceeded
     *   2. months left in the year < envelopeMinMonthsRemaining       -> silent
     *   3. spentInYear >= budget * envelopeConsumedShare              -> envelope_consumed
     *   4. otherwise                                                  -> silent
     *
     * Three rules from the monthly table are deliberately absent, and each
     * absence is the fix rather than an oversight:
     *
     * - No projection, ever. `spent × daysInMonth / day` answers "at this rate,
     *   where does the month close?", and an envelope has no rate to extrapolate.
     *   A yearly budget consumed by one purchase would project to twelve times
     *   that purchase, which is noise wearing the costume of a warning.
     * - No lumpiness check. `isLumpy` exists to detect "this month does not look
     *   like a rate" — for an envelope that is not an anomaly to guard against,
     *   it is the expected shape. Asking the question here would silence exactly
     *   the categories this branch was built to serve.
     * - No `minDayForProjection`. That guard protects a projection from a
     *   sample too small to extrapolate; with no projection there is nothing to
     *   protect. Depletion on day 2 is as true as depletion on day 28.
     *
     * The caller's `spent > 0` guard still applies, so the coach only speaks
     * about an envelope in a month the user actually touched it. A category
     * exhausted in March stays quiet through a silent April — narrating a fact
     * with no cause this month would break "the fact and its cause" (ADR-0009).
     */
    private function evaluateEnvelope(
        CategoryMonthSnapshot $snapshot,
        MonthCursor $cursor,
        PaceThresholds $thresholds,
    ): ?PaceObservation {
        $consumed = $snapshot->spentInYear;

        if ($consumed > $snapshot->monthlyBudget) {
            return $this->observation(
                $snapshot,
                $cursor,
                band: 'envelope_exceeded',
                isLumpy: false,
                projected: null,
                spent: $consumed,
                periodKind: BudgetPeriod::YEARLY,
            );
        }

        $monthsRemaining = 12 - $cursor->periodMonth->month;

        if ($monthsRemaining < $thresholds->envelopeMinMonthsRemaining) {
            return null;
        }

        if ($consumed >= $snapshot->monthlyBudget * $thresholds->envelopeConsumedShare) {
            return $this->observation(
                $snapshot,
                $cursor,
                band: 'envelope_consumed',
                isLumpy: false,
                projected: null,
                spent: $consumed,
                periodKind: BudgetPeriod::YEARLY,
                monthsRemaining: $monthsRemaining,
            );
        }

        return null;
    }

    private function observation(
        CategoryMonthSnapshot $snapshot,
        MonthCursor $cursor,
        string $band,
        bool $isLumpy,
        ?float $projected,
        ?float $spent = null,
        BudgetPeriod $periodKind = BudgetPeriod::MONTHLY,
        ?int $monthsRemaining = null,
    ): PaceObservation {
        return new PaceObservation(
            subjectKey: "category:{$snapshot->categoryId}",
            categoryId: $snapshot->categoryId,
            name: $snapshot->name,
            band: $band,
            isLumpy: $isLumpy,
            // Envelope bands pass the year-to-date figure explicitly, so `spent`
            // and `budget` always share a unit and their ratio needs no context.
            spent: $spent ?? $snapshot->spent,
            budget: $snapshot->monthlyBudget,
            projected: $projected,
            dayOfMonth: $cursor->day,
            periodKind: $periodKind,
            monthsRemaining: $monthsRemaining,
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

    /**
     * "Already past the line" outranks "heading for it", whichever family the
     * band belongs to. The two families share ranks rather than stacking into a
     * four-rung ladder because they are not comparable in severity — an envelope
     * 85% gone is not inherently milder or worse than a month projected over,
     * and inventing an order between them would decide, silently, which of a
     * user's categories gets dropped by the max-observations cut.
     */
    private function severityRank(PaceObservation $observation): int
    {
        return match ($observation->band) {
            'over_budget', 'envelope_exceeded' => 0,
            'projected_over', 'envelope_consumed' => 1,
            default => 2,
        };
    }

    private function severityScore(PaceObservation $observation): float
    {
        return match ($observation->band) {
            // `spent` and `budget` always share a unit (PaceObservation's
            // docblock), so this ratio is comparable across both families.
            'over_budget', 'envelope_exceeded', 'envelope_consumed' => $observation->spent / $observation->budget,
            'projected_over' => $observation->projected !== null ? $observation->projected / $observation->budget : 0.0,
            default => 0.0,
        };
    }
}
