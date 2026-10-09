<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

use App\Enums\PriceBasis;
use App\Enums\PriceKind;
use Carbon\CarbonImmutable;

/**
 * A price a source adapter wants stored, before it has an id. The adapters (and
 * their pure parsers) build these; only `PriceRepository` turns one into a row.
 *
 * Money fields are numeric strings with up to four decimals — the same shape the
 * column hands back — so a unit price is never a float between the parser and
 * PostgreSQL. `$unit` is the product's catalogue unit at ingest time and
 * `$unitPrice` is per that unit.
 */
final class ObservationDraft
{
    public function __construct(
        public readonly int $productId,
        public readonly string $source,
        public readonly PriceKind $kind,
        public readonly string $unit,
        public readonly string $unitPrice,
        public readonly ?string $referencePrice,
        public readonly ?string $priceMin,
        public readonly ?string $priceMax,
        public readonly int $sampleSize,
        public readonly PriceBasis $basis,
        public readonly CarbonImmutable $periodStart,
        public readonly CarbonImmutable $periodEnd,
        public readonly CarbonImmutable $observedAt,
        public readonly ?string $sourceRef,
        public readonly bool $isQuarantined = false,
    ) {}
}
