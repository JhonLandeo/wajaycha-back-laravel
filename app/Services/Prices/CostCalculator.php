<?php

declare(strict_types=1);

namespace App\Services\Prices;

use InvalidArgumentException;

/**
 * Money arithmetic in integers (design D16).
 *
 * `estimated_cost = round_half_up(to_buy x unit_price, 2)`. Done on floats this
 * is fragile: 3.995 is not representable, so `round(0.5 * 7.99, 2)` can yield
 * 3.99. Instead the unit price travels as the numeric string PostgreSQL returns
 * (four decimals) and becomes an integer count of ten-thousandths; the quantity
 * (three decimals) becomes thousandths; their product is in 1e-7 soles and one
 * integer division lands it on cents.
 *
 * Pure: values in, values out. No clock, no model, no database.
 */
final class CostCalculator
{
    /** 1e-7 soles per unit of (thousandths x ten-thousandths); 1e5 of them make a cent. */
    private const UNITS_PER_CENT = 100_000;

    private const HALF_CENT = 50_000;

    /**
     * Proportional cost of the quantity still needed, in cents (not
     * pack-rounded: 0.1 kg of a 13.50/kg product costs 1.35).
     *
     * @param  string  $unitPrice  Non-negative decimal as a string ("8.9000", "7.99").
     *
     * @throws InvalidArgumentException when the price is not a plain non-negative decimal
     */
    public function lineCostCents(float $toBuy, string $unitPrice): int
    {
        $thousandths = (int) round($toBuy * 1000);

        return intdiv($thousandths * $this->toTenThousandths($unitPrice) + self::HALF_CENT, self::UNITS_PER_CENT);
    }

    /**
     * A stored unit price shown at two decimals, half up, as cents. Output only:
     * the cost itself is always computed from the exact stored value.
     */
    public function unitPriceToCents(string $unitPrice): int
    {
        return intdiv($this->toTenThousandths($unitPrice) + 50, 100);
    }

    public function centsToAmount(int $cents): float
    {
        return round($cents / 100, 2);
    }

    public function amountToCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function toTenThousandths(string $price): int
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $price, $m) !== 1) {
            throw new InvalidArgumentException("Unit price is not a non-negative decimal: '{$price}'.");
        }

        $fraction = $m[2] ?? '';
        $value = (int) $m[1] * 10_000 + (int) substr(str_pad($fraction, 4, '0'), 0, 4);

        // Half-up on the fifth decimal digit, the only one that can change the fourth.
        if (strlen($fraction) > 4 && (int) $fraction[4] >= 5) {
            $value++;
        }

        return $value;
    }
}
