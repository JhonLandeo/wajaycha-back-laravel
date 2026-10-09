<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

use App\Enums\PriceBasis;

/**
 * The outcome of filtering and pricing one product's Plaza Vea candidates: the
 * median price per catalogue unit with its spread, how many offers backed it,
 * and a count of rejections by reason (never a price) for the run log.
 */
final class VtexSelection
{
    /**
     * @param  list<string>  $skuIds
     * @param  array<string, int>  $rejections  reason => how many offers
     */
    public function __construct(
        public readonly ?string $unitPrice,
        public readonly ?string $referencePrice,
        public readonly ?string $priceMin,
        public readonly ?string $priceMax,
        public readonly int $accepted,
        public readonly PriceBasis $basis,
        public readonly array $skuIds,
        public readonly array $rejections,
    ) {}

    public function hasPrice(): bool
    {
        return $this->unitPrice !== null;
    }
}
