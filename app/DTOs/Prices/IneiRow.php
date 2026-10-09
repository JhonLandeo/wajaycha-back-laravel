<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

/**
 * One product line of INEI's Cuadro N.19 after repair: the label and unit as
 * printed, the latest and the previous month as numeric strings (four
 * decimals), and whether the month-over-month jump put it in quarantine.
 */
final class IneiRow
{
    public function __construct(
        public readonly string $label,
        public readonly string $unit,
        public readonly string $price,
        public readonly string $previousPrice,
        public readonly bool $isQuarantined,
    ) {}
}
