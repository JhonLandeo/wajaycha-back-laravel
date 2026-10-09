<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

/**
 * A seeded catalogue product as the ingestion needs it: its id, its slug (the
 * mapping key) and the unit it is bought in, which is the unit every stored
 * price is expressed per.
 */
final class CatalogueProduct
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $unit,
    ) {}
}
