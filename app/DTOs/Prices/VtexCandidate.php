<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

/**
 * One purchasable item of a VTEX legacy search answer, with the first seller's
 * offer. Prices are numeric strings (four decimals) so they never travel as
 * floats. `$measurementUnit` is VTEX's own tag: `kg` means the price is per
 * kilogram, `un` means it is the price of the pack as named.
 */
final class VtexCandidate
{
    public function __construct(
        public readonly string $name,
        public readonly string $itemName,
        public readonly string $measurementUnit,
        public readonly string $price,
        public readonly string $listPrice,
        public readonly bool $isAvailable,
        public readonly int $availableQuantity,
        public readonly string $skuId,
    ) {}
}
