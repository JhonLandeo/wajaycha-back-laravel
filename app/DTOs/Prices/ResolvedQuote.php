<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

use App\Enums\PriceState;

/**
 * A usable quote together with what the consumer needs to show it: its state,
 * the source label and the attribution text. A quote only becomes a
 * `ResolvedQuote` when it has a date and an attribution template.
 */
final class ResolvedQuote
{
    public function __construct(
        public readonly Quote $quote,
        public readonly PriceState $state,
        public readonly string $sourceLabel,
        public readonly string $attribution,
    ) {}
}
