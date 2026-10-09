<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The canonical measurement unit a Product declares, and the same value
 * every PantryItem and ConsumptionHabit row must match at write time
 * (design.md D4).
 *
 * Unlike BudgetPeriod::fromColumn(), this enum has NO safe fallback.
 * Coercing an unrecognised value to a default unit would mis-scale a
 * quantity — 500 g is not 500 kg — so `tryFrom()` returning null becomes the
 * unit-mismatch signal instead of a guess.
 */
enum Unit: string
{
    case Kg = 'kg';
    case G = 'g';
    case L = 'l';
    case Ml = 'ml';
    case Unidad = 'unidad';
    case Atado = 'atado';
    case Paquete = 'paquete';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(fn (self $unit): string => $unit->value, self::cases());
    }
}
