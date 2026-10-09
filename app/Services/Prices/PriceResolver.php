<?php

declare(strict_types=1);

namespace App\Services\Prices;

use App\DTOs\Prices\PricePolicy;
use App\DTOs\Prices\Quote;
use App\DTOs\Prices\ResolvedPrice;
use App\DTOs\Prices\ResolvedQuote;
use App\Enums\PriceKind;
use App\Enums\PriceState;
use App\Enums\Unit;
use Carbon\CarbonImmutable;

/**
 * Chooses the price of one product (spec "Resolution precedence for a line
 * price"). Values in, a decision out: no clock, no database, no model — `asOf`
 * is the plan's reference date and the policies are the ENABLED sources, so a
 * disabled source is simply absent here (design D3, D13).
 *
 * Order of preference among usable retail quotes in the line's unit: the
 * best-ranked FRESH quote; else the best-ranked STALE quote; else unknown. A
 * fresh lower-ranked quote therefore beats a stale higher-ranked one. Expired
 * quotes are dropped; wholesale quotes never price a line.
 */
final class PriceResolver
{
    public function __construct(private readonly AttributionFormatter $attribution) {}

    /**
     * @param  Quote[]  $quotes  May hold other products' quotes; only `$productId`'s are read.
     * @param  array<string, PricePolicy>  $policies  Enabled sources, keyed by source key.
     */
    public function resolve(int $productId, Unit $unit, array $quotes, array $policies, CarbonImmutable $asOf): ResolvedPrice
    {
        $today = CalendarDays::number($asOf->setTimezone('America/Lima'));

        $candidates = [];
        foreach ($this->newestPerSource($productId, $unit, $quotes, $policies) as $source => $quote) {
            $policy = $policies[$source];
            // Both are guaranteed by newestPerSource(); the checks narrow the types.
            $periodEnd = $quote->periodEnd;
            $template = $policy->attribution;
            if ($periodEnd === null || $template === null) {
                continue;
            }

            $state = $policy->classify($today - CalendarDays::number($periodEnd));
            if ($state === null) {
                continue;
            }

            $candidates[] = [
                'rank' => $policy->rank,
                'resolved' => new ResolvedQuote($quote, $state, $policy->label, $this->attribution->format($template, $periodEnd)),
            ];
        }

        if ($candidates === []) {
            return ResolvedPrice::unknown();
        }

        // Fresh before stale, then best rank first; the source key breaks ties
        // so the result never depends on input order.
        usort($candidates, static fn (array $a, array $b): int => [$a['resolved']->state === PriceState::Fresh ? 0 : 1, $a['rank'], $a['resolved']->quote->source]
            <=> [$b['resolved']->state === PriceState::Fresh ? 0 : 1, $b['rank'], $b['resolved']->quote->source]);

        $ordered = array_map(static fn (array $c): ResolvedQuote => $c['resolved'], $candidates);
        $chosen = array_shift($ordered);

        return new ResolvedPrice($chosen->state, $chosen, $ordered);
    }

    /**
     * Retail quotes of this product, in this unit, from an enabled retail
     * source, dated and with an attribution template — newest per source.
     *
     * @param  Quote[]  $quotes
     * @param  array<string, PricePolicy>  $policies
     * @return array<string, Quote>
     */
    private function newestPerSource(int $productId, Unit $unit, array $quotes, array $policies): array
    {
        $newest = [];

        foreach ($quotes as $quote) {
            $policy = $policies[$quote->source] ?? null;

            if ($quote->productId !== $productId
                || $quote->kind !== PriceKind::Retail
                || $quote->unit !== $unit->value
                || $policy === null
                || $policy->kind !== PriceKind::Retail
                || $policy->attribution === null
                || $policy->attribution === ''
                || $quote->periodStart === null
                || $quote->periodEnd === null) {
                continue;
            }

            $current = $newest[$quote->source] ?? null;
            if ($current === null || $quote->periodEnd->greaterThan($current->periodEnd)) {
                $newest[$quote->source] = $quote;
            }
        }

        return $newest;
    }
}
