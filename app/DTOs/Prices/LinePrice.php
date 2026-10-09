<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

use App\Enums\PriceBasis;
use App\Enums\PriceState;
use Carbon\CarbonImmutable;

/**
 * The price annotation of one shopping-list line (spec "Per-line price
 * annotation"). For `Unknown` every price field is null and `alternatives` is
 * empty — an unknown price is never a number, least of all 0.
 *
 * `$unitPrice` is the stored value rounded to two decimals for display;
 * `$estimatedCost` was computed from the exact stored value, proportionally to
 * the quantity still needed (not pack-rounded).
 */
final class LinePrice
{
    /**
     * @param  PriceAlternative[]  $alternatives
     */
    public function __construct(
        public readonly PriceState $state,
        public readonly ?float $unitPrice,
        public readonly ?float $estimatedCost,
        public readonly ?PriceBasis $basis,
        public readonly ?string $source,
        public readonly ?string $sourceLabel,
        public readonly ?CarbonImmutable $periodStart,
        public readonly ?CarbonImmutable $periodEnd,
        public readonly ?string $attribution,
        public readonly array $alternatives,
    ) {}

    public static function unknown(): self
    {
        return new self(PriceState::Unknown, null, null, null, null, null, null, null, null, []);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'unit_price' => $this->unitPrice,
            'estimated_cost' => $this->estimatedCost,
            'state' => $this->state->value,
            'basis' => $this->basis?->value,
            'source' => $this->source,
            'source_label' => $this->sourceLabel,
            'period_start' => $this->periodStart?->toDateString(),
            'period_end' => $this->periodEnd?->toDateString(),
            'attribution' => $this->attribution,
            'alternatives' => array_map(
                static fn (PriceAlternative $alternative): array => $alternative->toArray(),
                $this->alternatives,
            ),
        ];
    }
}
