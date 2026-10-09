<?php

declare(strict_types=1);

namespace App\Services\Prices;

use App\DTOs\Prices\PricePolicy;
use App\DTOs\Prices\Quote;
use App\DTOs\Prices\WholesaleTrend;
use App\Enums\PriceKind;
use Carbon\CarbonImmutable;

/**
 * Compares the latest usable wholesale point of a product with the same
 * source's point about a week earlier (design D14). Pure: values in, a verdict
 * out — thresholds arrive through the constructor (the container builds it from
 * `prices.trend`), the clock through `$asOf`, the enabled sources through the
 * policies.
 *
 * It answers null whenever the comparison would be unfair: fewer than two
 * points, a latest point older than `maxAgeDays`, no earlier point inside the
 * look-back window, or any point between them from a different source (a source
 * switch changes the level of the series, not the market).
 */
final class WholesaleTrendCalculator
{
    private const IDEAL_GAP_DAYS = 7;

    public function __construct(
        private readonly int $maxAgeDays = 4,
        private readonly int $windowMinDays = 5,
        private readonly int $windowMaxDays = 10,
        private readonly float $flatEpsilonPct = 3.0,
    ) {}

    /**
     * @param  Quote[]  $quotes  May hold other products' and retail quotes; they are ignored.
     * @param  array<string, PricePolicy>  $policies  Enabled sources, keyed by source key.
     */
    public function compute(int $productId, array $quotes, array $policies, CarbonImmutable $asOf): ?WholesaleTrend
    {
        $series = $this->series($productId, $quotes, $policies);
        if (count($series) < 2) {
            return null;
        }

        $latest = $series[array_key_last($series)];
        $latestDay = CalendarDays::number($latest->periodEnd);

        if (CalendarDays::number($asOf->setTimezone('America/Lima')) - $latestDay > $this->maxAgeDays) {
            return null;
        }

        $prior = $this->nearestPrior($series, $latest, $latestDay);
        if ($prior === null || ! $this->sameSourceBetween($series, $prior, $latest)) {
            return null;
        }

        $priorPrice = (float) $prior->unitPrice;
        if ($priorPrice <= 0.0) {
            return null;
        }

        $changePct = round(((float) $latest->unitPrice - $priorPrice) / $priorPrice * 100, 1);

        return new WholesaleTrend(
            direction: match (true) {
                abs($changePct) < $this->flatEpsilonPct => 'flat',
                $changePct > 0 => 'up',
                default => 'down',
            },
            changePct: $changePct,
            source: $latest->source,
            asOf: $latest->periodEnd,
        );
    }

    /**
     * Enabled wholesale points of the product, oldest first (ties by source key
     * so the order never depends on how the input arrived). Every returned quote
     * has a period end.
     *
     * @param  Quote[]  $quotes
     * @param  array<string, PricePolicy>  $policies
     * @return list<Quote&object{periodEnd: CarbonImmutable}>
     */
    private function series(int $productId, array $quotes, array $policies): array
    {
        $series = array_values(array_filter(
            $quotes,
            static fn (Quote $quote): bool => $quote->productId === $productId
                && $quote->kind === PriceKind::Wholesale
                && $quote->periodEnd !== null
                && ($policies[$quote->source] ?? null)?->kind === PriceKind::Wholesale,
        ));

        usort($series, static fn (Quote $a, Quote $b): int => [$a->periodEnd?->getTimestamp(), $a->source] <=> [$b->periodEnd?->getTimestamp(), $b->source]);

        /** @var list<Quote&object{periodEnd: CarbonImmutable}> $series */
        return $series;
    }

    /**
     * The point inside the look-back window closest to a week before the latest.
     *
     * @param  list<Quote>  $series
     */
    private function nearestPrior(array $series, Quote $latest, int $latestDay): ?Quote
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($series as $point) {
            if ($point === $latest || $point->periodEnd === null) {
                continue;
            }

            $gap = $latestDay - CalendarDays::number($point->periodEnd);
            if ($gap < $this->windowMinDays || $gap > $this->windowMaxDays) {
                continue;
            }

            $distance = abs($gap - self::IDEAL_GAP_DAYS);
            if ($distance < $bestDistance) {
                $best = $point;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * @param  list<Quote>  $series
     */
    private function sameSourceBetween(array $series, Quote $prior, Quote $latest): bool
    {
        foreach ($series as $point) {
            if ($point->periodEnd === null || $prior->periodEnd === null || $latest->periodEnd === null) {
                continue;
            }

            $inRange = $point->periodEnd->greaterThanOrEqualTo($prior->periodEnd)
                && $point->periodEnd->lessThanOrEqualTo($latest->periodEnd);

            if ($inRange && $point->source !== $latest->source) {
                return false;
            }
        }

        return true;
    }
}
