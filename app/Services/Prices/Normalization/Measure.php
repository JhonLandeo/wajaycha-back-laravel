<?php

declare(strict_types=1);

namespace App\Services\Prices\Normalization;

/**
 * The quantity a quoted price buys, in the base unit of its dimension (kg, l,
 * units) as a numeric string.
 *
 * `$bulk` separates "priced by the kilo" (VTEX `measurementUnit: kg`, INEI's
 * KILOGRAMO) from "a pack that happens to weigh a kilo": the price of a bulk
 * item is already per base unit, and a bulk item is never "one unit" of a
 * count product.
 */
final class Measure
{
    public function __construct(
        public readonly string $quantity,
        public readonly Dimension $dimension,
        public readonly bool $bulk = false,
    ) {}

    /** The price is per one base unit (per kg, per litre). */
    public static function bulk(Dimension $dimension): self
    {
        return new self('1', $dimension, true);
    }

    /** The price is for a whole pack of `$quantity` base units. */
    public static function pack(string $quantity, Dimension $dimension): self
    {
        return new self($quantity, $dimension);
    }
}
