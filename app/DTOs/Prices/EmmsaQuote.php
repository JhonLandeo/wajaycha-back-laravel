<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

/**
 * The wholesale price of one catalogue product on one day, reduced from the
 * EMMSA varieties that map to it: the mean of their averages and the widest
 * range, with how many report lines went into it.
 */
final class EmmsaQuote
{
    public function __construct(
        public readonly string $avg,
        public readonly string $min,
        public readonly string $max,
        public readonly int $rows,
    ) {}
}
