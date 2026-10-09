<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

use App\Enums\PriceBasis;
use App\Enums\PriceKind;
use Carbon\CarbonImmutable;

/**
 * A stored price observation as the pure deciders see it: values only, never
 * the Eloquent row.
 *
 * `$unitPrice` stays the numeric string PostgreSQL returned (four decimals) so
 * it reaches the cost arithmetic without ever becoming a float. `$unit` is the
 * raw column text, compared against the line's unit by value — an unrecognised
 * stored unit simply never matches.
 *
 * A null period means the quote cannot be dated, and an undated quote is never
 * exposed (spec "Attribution and date always present").
 */
final class Quote
{
    public function __construct(
        public readonly int $productId,
        public readonly string $source,
        public readonly PriceKind $kind,
        public readonly string $unit,
        public readonly string $unitPrice,
        public readonly PriceBasis $basis,
        public readonly ?CarbonImmutable $periodStart,
        public readonly ?CarbonImmutable $periodEnd,
    ) {}
}
