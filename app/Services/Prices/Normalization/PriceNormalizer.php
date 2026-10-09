<?php

declare(strict_types=1);

namespace App\Services\Prices\Normalization;

use App\Enums\PriceBasis;

/**
 * Turns "this much S/ for this quantity" into "S/ per catalogue unit" (design
 * D5), and is the only place a unit is converted. User quantities are never
 * touched: this only decides what a source's price means per the unit the
 * product is bought in.
 *
 * Targets: `kg` takes mass, `l` takes volume, and the count units (`unidad`,
 * `paquete`, `atado`) take counts. A count product priced by weight needs the
 * curated `kg_per_unit`, and the result is flagged `equivalence` so the
 * consumer shows it with a "~". Anything else that does not line up is
 * rejected with a stable reason, never scaled by guesswork.
 *
 * Pure: strings in, strings out, arithmetic through bcmath so a price never
 * becomes a float between the parser and PostgreSQL. Rounding happens once, at
 * the fifth decimal, half-up.
 */
final class PriceNormalizer
{
    private const COUNT_UNITS = ['unidad', 'paquete', 'atado'];

    private const WORKING_SCALE = 10;

    private const STORED_SCALE = 4;

    public function normalize(
        string $price,
        Measure $measure,
        string $targetUnit,
        ?string $kgPerUnit = null,
        bool $assumeSingle = false,
    ): NormalizedPrice {
        if (! is_numeric($price) || bccomp($price, '0', self::WORKING_SCALE) <= 0) {
            return NormalizedPrice::rejected('invalid_price');
        }

        if (! is_numeric($measure->quantity) || bccomp($measure->quantity, '0', self::WORKING_SCALE) <= 0) {
            return NormalizedPrice::rejected('invalid_quantity');
        }

        return match (true) {
            $targetUnit === 'kg' => $this->direct($price, $measure, Dimension::Mass),
            $targetUnit === 'l' => $this->direct($price, $measure, Dimension::Volume),
            in_array($targetUnit, self::COUNT_UNITS, true) => $this->toCount($price, $measure, $kgPerUnit, $assumeSingle),
            default => NormalizedPrice::rejected('unsupported_unit'),
        };
    }

    private function direct(string $price, Measure $measure, Dimension $expected): NormalizedPrice
    {
        if ($measure->dimension !== $expected) {
            return NormalizedPrice::rejected('dimension_mismatch');
        }

        return NormalizedPrice::of($this->round(bcdiv($price, $measure->quantity, self::WORKING_SCALE)), PriceBasis::Measured);
    }

    private function toCount(string $price, Measure $measure, ?string $kgPerUnit, bool $assumeSingle): NormalizedPrice
    {
        if ($measure->dimension === Dimension::Count) {
            return NormalizedPrice::of($this->round(bcdiv($price, $measure->quantity, self::WORKING_SCALE)), PriceBasis::Measured);
        }

        // A pack with no count in it (a 170 g tin, a bunch) is one unit when the
        // mapping says so. A price per kilo is never "one unit".
        if ($assumeSingle && ! $measure->bulk) {
            return NormalizedPrice::of($this->round($price), PriceBasis::Measured);
        }

        if ($measure->dimension === Dimension::Mass && $kgPerUnit !== null && is_numeric($kgPerUnit) && bccomp($kgPerUnit, '0', self::WORKING_SCALE) > 0) {
            $perKg = bcdiv($price, $measure->quantity, self::WORKING_SCALE);

            return NormalizedPrice::of($this->round(bcmul($perKg, $kgPerUnit, self::WORKING_SCALE)), PriceBasis::Equivalence);
        }

        return NormalizedPrice::rejected('no_equivalence');
    }

    /** Half-up to the stored scale (bcmath truncates, so add half a unit first). */
    private function round(string $value): string
    {
        return bcadd($value, '0.00005', self::STORED_SCALE);
    }
}
