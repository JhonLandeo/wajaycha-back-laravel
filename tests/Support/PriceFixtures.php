<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DTOs\Prices\PricePolicy;
use App\DTOs\Prices\Quote;
use App\Enums\PriceBasis;
use App\Enums\PriceKind;
use Carbon\CarbonImmutable;

/**
 * Values for the pure price deciders. Mirrors the shipped `config/prices.php`
 * windows so a test reads like production, but is built by hand: the unit
 * tests that use it boot no application and read no configuration.
 */
final class PriceFixtures
{
    /** The day the plan tests compute "as of" (Lima date of 2026-09-24 12:00). */
    public const AS_OF_DAY = '2026-09-24';

    /**
     * @return array<string, PricePolicy> the requested sources, keyed by source key
     */
    public static function policies(string ...$sources): array
    {
        $all = [
            'plazavea' => new PricePolicy('plazavea', PriceKind::Retail, 'Plaza Vea', 1, 8, 21, 'Precio online de Plaza Vea al {dd/mm}'),
            'inei' => new PricePolicy('inei', PriceKind::Retail, 'INEI', 2, 75, 135, 'Promedio Lima INEI, {mmm} {yyyy}'),
            'emmsa' => new PricePolicy('emmsa', PriceKind::Wholesale, 'EMMSA', 10, 4, 4, null),
            'gmml' => new PricePolicy('gmml', PriceKind::Wholesale, 'GMML', 11, 4, 4, null),
        ];

        return array_intersect_key($all, array_flip($sources));
    }

    /**
     * A quote whose period ends `$ageDays` before {@see self::AS_OF_DAY}. INEI
     * quotes cover the whole month the period end falls in, like production.
     */
    public static function quote(
        int $productId,
        string $source,
        string $unitPrice,
        int $ageDays = 1,
        string $unit = 'kg',
        PriceBasis $basis = PriceBasis::Measured,
    ): Quote {
        $end = CarbonImmutable::parse(self::AS_OF_DAY)->subDays($ageDays);
        $kind = in_array($source, ['emmsa', 'gmml'], true) ? PriceKind::Wholesale : PriceKind::Retail;

        return new Quote($productId, $source, $kind, $unit, $unitPrice, $basis, $source === 'inei' ? $end->startOfMonth() : $end, $end);
    }
}
