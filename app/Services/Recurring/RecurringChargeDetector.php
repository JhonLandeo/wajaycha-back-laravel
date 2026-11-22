<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\DTOs\Recurring\RecurringCharge;
use App\Enums\RecurringCadence;
use Carbon\CarbonImmutable;

/**
 * Decides which merchants charge on their own, and what that costs per year.
 *
 * Pure, like every other decider in this codebase: values in, values out, no
 * connection and no model. The alternative — a table with an `is_recurring`
 * column — would be a second truth that goes stale the moment a subscription is
 * cancelled, and it would have to be kept in sync by the very heuristics that
 * can simply be re-run (ADR-0051).
 *
 * Two signals together, because either one alone is a lie:
 *
 * - **Amount stability.** A fixed-amount charge is a commitment; a variable one
 *   is a habit. Groceries arrive weekly and are not a subscription no matter how
 *   regular the visits are.
 * - **Interval regularity.** A steady amount on irregular dates is a coincidence
 *   — two purchases of the same product months apart are not a plan.
 *
 * Neither test uses a raw spread. Both are compared against the charge's own
 * scale so that a S/ 2,000 rent and a S/ 15 streaming plan are judged the same
 * way, which is the same reason `SpendingRhythmClassifier` uses a coefficient of
 * variation.
 */
final class RecurringChargeDetector
{
    /**
     * @param  array<int, object{merchant: string, amount: float|string, date_operation: string}>  $charges
     *                                                                                                      one row per movement, any order
     * @param  float  $variationThreshold  coefficient of variation at or below which
     *                                     the amount counts as stable
     * @param  float  $regularShare  share of intervals that must sit near the median
     *                               before the spacing counts as a rhythm
     * @param  float  $intervalToleranceShare  how far an interval may drift from the
     *                                         nominal cadence, as a share of it
     * @return RecurringCharge[] ordered by annual cost, largest first
     */
    public function detect(
        array $charges,
        float $variationThreshold,
        float $regularShare,
        float $intervalToleranceShare,
        CarbonImmutable $today,
        string $timezone,
    ): array {
        $groups = $this->group($charges, $timezone);

        $today = $today->setTimezone($timezone)->startOfDay();

        $recurring = [];

        foreach ($groups as $group) {
            $charge = $this->detectGroup(
                $group['merchant'],
                $group['charges'],
                $variationThreshold,
                $regularShare,
                $intervalToleranceShare,
                $today,
            );

            if ($charge !== null) {
                $recurring[] = $charge;
            }
        }

        usort(
            $recurring,
            static fn (RecurringCharge $a, RecurringCharge $b): int => $b->annualEquivalent <=> $a->annualEquivalent
                ?: $a->merchant <=> $b->merchant,
        );

        return $recurring;
    }

    /**
     * Groups by a case-folded merchant key but keeps the most recent spelling
     * for display. `entity_clean` already normalises most of this, but the
     * fallback is the raw description, and "Netflix" and "NETFLIX" are one
     * subscription.
     *
     * @param  array<int, object{merchant: string, amount: float|string, date_operation: string}>  $charges
     * @return array<string, array{merchant: string, charges: array<int, array{date: CarbonImmutable, amount: float}>}>
     */
    private function group(array $charges, string $timezone): array
    {
        $groups = [];

        foreach ($charges as $row) {
            $merchant = trim((string) $row->merchant);
            $amount = (float) $row->amount;

            if ($merchant === '' || $amount <= 0.0) {
                continue;
            }

            $key = mb_strtolower($merchant);

            $groups[$key] ??= ['merchant' => $merchant, 'charges' => []];
            $groups[$key]['merchant'] = $merchant;
            $groups[$key]['charges'][] = [
                // Parse in the target timezone: a naive `date_operation` means
                // that calendar day in Lima, while a value carrying an offset
                // keeps its own and is then converted. Converting first would
                // move a midnight timestamp to the previous day.
                'date' => CarbonImmutable::parse((string) $row->date_operation, $timezone)
                    ->setTimezone($timezone)
                    ->startOfDay(),
                'amount' => $amount,
            ];
        }

        foreach ($groups as $key => $group) {
            usort(
                $groups[$key]['charges'],
                static fn (array $a, array $b): int => $a['date'] <=> $b['date'],
            );
        }

        return $groups;
    }

    /**
     * @param  array<int, array{date: CarbonImmutable, amount: float}>  $charges
     */
    private function detectGroup(
        string $merchant,
        array $charges,
        float $variationThreshold,
        float $regularShare,
        float $intervalToleranceShare,
        CarbonImmutable $today,
    ): ?RecurringCharge {
        $occurrences = count($charges);

        $intervals = [];
        for ($index = 1; $index < $occurrences; $index++) {
            // Same-day charges are skipped for the spacing test but still count
            // as occurrences: two identical charges on one day are a duplicate
            // or a second plan, not a zero-day rhythm.
            $days = $charges[$index - 1]['date']->diffInDays($charges[$index]['date']);

            if ($days > 0) {
                $intervals[] = $days;
            }
        }

        if ($intervals === []) {
            return null;
        }

        $median = $this->median($intervals);
        $cadence = $this->cadenceFor($median);

        if ($cadence === null || $occurrences < $cadence->minOccurrences()) {
            return null;
        }

        $last = $charges[$occurrences - 1];

        // A cancelled subscription keeps its history inside the window. Without
        // this guard, twelve monthly charges from a year ago would read as
        // something the user still pays. The slack belongs to the rhythm: a
        // monthly charge may arrive days late, not months.
        $daysSinceLastCharge = abs((int) $today->diffInDays($last['date']));

        if ($daysSinceLastCharge > $cadence->nominalDays() + $cadence->graceDays()) {
            return null;
        }

        $amounts = array_map(
            static fn (array $charge): float => $charge['amount'],
            $charges,
        );

        if ($this->variation($amounts) > $variationThreshold) {
            return null;
        }

        $tolerance = $this->toleranceDays($cadence, $intervalToleranceShare);
        $regular = count(array_filter(
            $intervals,
            static fn (float $days): bool => abs($days - $median) <= $tolerance,
        ));

        if ($regular / count($intervals) < $regularShare) {
            return null;
        }

        $amount = $last['amount'];

        return new RecurringCharge(
            merchant: $merchant,
            cadence: $cadence,
            amount: $amount,
            averageAmount: array_sum($amounts) / $occurrences,
            occurrences: $occurrences,
            lastChargedOn: $last['date']->toDateString(),
            nextExpectedOn: $last['date']->addDays((int) round($median))->toDateString(),
            monthlyEquivalent: $this->monthlyEquivalent($cadence, $amount),
            annualEquivalent: $this->annualEquivalent($cadence, $amount),
        );
    }

    private function cadenceFor(float $medianDays): ?RecurringCadence
    {
        foreach (RecurringCadence::cases() as $cadence) {
            // Each rhythm gets its own absolute window instead of one shared
            // percentage, because the scale is different: a week that drifts a
            // day is normal, a year that drifts a day is noise. A fortnightly
            // charge falls into none of them, which is the honest outcome — this
            // reading does not name that rhythm, so it stays silent rather than
            // filing it under the nearest label.
            if (abs($medianDays - $cadence->nominalDays()) <= $this->cadenceWindow($cadence)) {
                return $cadence;
            }
        }

        return null;
    }

    /**
     * The outer edge of the cadence band: a monthly charge may land 5 days early
     * or late and still be monthly; an annual one gets proportionally more room
     * because a yearly renewal moves with the calendar.
     */
    private function cadenceWindow(RecurringCadence $cadence): int
    {
        return match ($cadence) {
            RecurringCadence::WEEKLY => 2,
            RecurringCadence::MONTHLY => 5,
            RecurringCadence::ANNUAL => 30,
        };
    }

    private function toleranceDays(RecurringCadence $cadence, float $share): int
    {
        return (int) max(2, min(30, ceil($cadence->nominalDays() * $share)));
    }

    /**
     * @param  float[]  $amounts
     */
    private function variation(array $amounts): float
    {
        $count = count($amounts);

        if ($count === 0) {
            return 0.0;
        }

        $mean = array_sum($amounts) / $count;

        if ($mean <= 0.0) {
            return INF;
        }

        $squared = array_sum(array_map(
            static fn (float $amount): float => ($amount - $mean) ** 2,
            $amounts,
        ));

        return sqrt($squared / $count) / $mean;
    }

    /**
     * @param  float[]  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private function monthlyEquivalent(RecurringCadence $cadence, float $amount): float
    {
        return match ($cadence) {
            RecurringCadence::WEEKLY => $amount * 52 / 12,
            RecurringCadence::MONTHLY => $amount,
            RecurringCadence::ANNUAL => $amount / 12,
        };
    }

    private function annualEquivalent(RecurringCadence $cadence, float $amount): float
    {
        return match ($cadence) {
            RecurringCadence::WEEKLY => $amount * 52,
            RecurringCadence::MONTHLY => $amount * 12,
            RecurringCadence::ANNUAL => $amount,
        };
    }
}
