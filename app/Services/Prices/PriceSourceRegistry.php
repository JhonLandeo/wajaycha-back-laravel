<?php

declare(strict_types=1);

namespace App\Services\Prices;

use App\DTOs\Prices\PricePolicy;
use App\Enums\PriceKind;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;

/**
 * The read side of the kill switch (design D3).
 *
 * It turns `config/prices.php` into the policies of the ENABLED sources on every
 * call and keeps no copy of its own, so flipping `PRICES_<KEY>_ENABLED` and
 * reloading config takes effect on the very next request — there is no cache in
 * this class to go stale. Disabled sources are simply absent from the result,
 * which is all the pure deciders need to ignore them.
 *
 * Not a decider itself: it reads configuration. The deciders (resolver, trend,
 * cost, attribution) receive its output as values.
 */
final class PriceSourceRegistry
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @return string[] every configured source key, enabled or not
     */
    public function sourceKeys(): array
    {
        return array_keys($this->sources());
    }

    public function knows(string $source): bool
    {
        return array_key_exists($source, $this->sources());
    }

    public function isEnabled(string $source): bool
    {
        return (bool) ($this->sources()[$source]['enabled'] ?? false);
    }

    /**
     * @return array<string, PricePolicy> enabled sources only, keyed by source key
     */
    public function enabledPolicies(): array
    {
        $policies = [];

        foreach ($this->sources() as $key => $source) {
            if (! ($source['enabled'] ?? false)) {
                continue;
            }

            $policies[$key] = new PricePolicy(
                source: $key,
                kind: PriceKind::from($source['kind']),
                label: $source['label'],
                rank: (int) $source['rank'],
                freshDays: (int) $source['fresh_days'],
                staleDays: (int) $source['stale_days'],
                attribution: $source['attribution'] ?? null,
            );
        }

        return $policies;
    }

    /**
     * The oldest `period_end` worth reading per enabled source: `asOf` minus the
     * stale window for retail, and for wholesale the latest point's maximum age
     * plus the trend's look-back window. Anything older is expired or useless,
     * so the repository can filter on it with an index-friendly range.
     *
     * @return array<string, CarbonImmutable>
     */
    public function cutoffsFor(CarbonImmutable $asOf): array
    {
        $day = $asOf->setTimezone('America/Lima')->startOfDay();
        $trendDays = (int) $this->config->get('prices.trend.max_age_days') + (int) $this->config->get('prices.trend.window_max_days');

        $cutoffs = [];
        foreach ($this->enabledPolicies() as $key => $policy) {
            $cutoffs[$key] = $day->subDays($policy->kind === PriceKind::Retail ? $policy->staleDays : $trendDays);
        }

        return $cutoffs;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function sources(): array
    {
        /** @var array<string, array<string, mixed>> */
        return (array) $this->config->get('prices.sources', []);
    }
}
