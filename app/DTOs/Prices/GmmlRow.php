<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

/**
 * One product line of the GMML daily bulletin. Prices are S/ per `$unit` of
 * measure and `$equivalentKg` is what that unit weighs in kilograms ("Equiv. en
 * kg"); the three price columns are the previous day, the bulletin's own day
 * ("Hoy") and the 7-day average. All numeric strings, exactly as printed.
 */
final class GmmlRow
{
    public function __construct(
        public readonly string $label,
        public readonly string $unit,
        public readonly string $equivalentKg,
        public readonly string $priceYesterday,
        public readonly string $priceToday,
        public readonly string $priceWeek,
    ) {}
}
