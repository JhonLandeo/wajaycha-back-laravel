<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

use App\Enums\PriceBasis;
use App\Enums\PriceState;
use Carbon\CarbonImmutable;

/**
 * A usable retail quote that was not chosen for the line. Always carries its
 * source label, attribution and period — the same guarantee as the chosen one.
 */
final class PriceAlternative
{
    public function __construct(
        public readonly string $source,
        public readonly string $sourceLabel,
        public readonly float $unitPrice,
        public readonly PriceState $state,
        public readonly PriceBasis $basis,
        public readonly CarbonImmutable $periodStart,
        public readonly CarbonImmutable $periodEnd,
        public readonly string $attribution,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'source_label' => $this->sourceLabel,
            'unit_price' => $this->unitPrice,
            'state' => $this->state->value,
            'basis' => $this->basis->value,
            'period_start' => $this->periodStart->toDateString(),
            'period_end' => $this->periodEnd->toDateString(),
            'attribution' => $this->attribution,
        ];
    }
}
