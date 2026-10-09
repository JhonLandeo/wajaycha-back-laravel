<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

/**
 * One line of EMMSA's wholesale report: product and variety as printed, and the
 * day's minimum, maximum and average in S/ per kg (numeric strings).
 */
final class EmmsaRow
{
    public function __construct(
        public readonly string $product,
        public readonly string $variety,
        public readonly string $min,
        public readonly string $max,
        public readonly string $avg,
    ) {}
}
