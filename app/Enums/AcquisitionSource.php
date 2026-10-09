<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a PantryItem entered the pantry.
 *
 * A `Gift` subtracts from the weekly shopping list exactly like a `Purchased`
 * item, but it must never affect spend or budget — no pantry item, of any
 * source, ever touches a Transaction (design.md, QA-4). The service that
 * computes the weekly list never branches on this value; it only decides
 * whether a write to Financial Analysis is even attempted, which for pantry
 * items is always "no".
 */
enum AcquisitionSource: string
{
    case Purchased = 'purchased';
    case Gift = 'gift';
    case Harvested = 'harvested';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(fn (self $source): string => $source->value, self::cases());
    }
}
