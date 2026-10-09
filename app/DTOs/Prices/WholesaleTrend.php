<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

use Carbon\CarbonImmutable;

/**
 * Informational wholesale movement for one product. It never prices a line and
 * never enters an estimate: it only says "the wholesale market moved this much
 * in about a week", with the source and the date of the latest point.
 */
final class WholesaleTrend
{
    public function __construct(
        public readonly string $direction,
        public readonly float $changePct,
        public readonly string $source,
        public readonly CarbonImmutable $asOf,
    ) {}

    /**
     * @return array{direction: string, change_pct: float, source: string, as_of: string}
     */
    public function toArray(): array
    {
        return [
            'direction' => $this->direction,
            'change_pct' => $this->changePct,
            'source' => $this->source,
            'as_of' => $this->asOf->toDateString(),
        ];
    }
}
