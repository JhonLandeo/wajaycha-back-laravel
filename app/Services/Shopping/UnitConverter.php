<?php

declare(strict_types=1);

namespace App\Services\Shopping;

use App\Enums\Unit;
use InvalidArgumentException;

/**
 * Conversión pura entre unidades compatibles del catálogo.
 *
 * Las recetas pueden declarar 200 g de arroz sobre un producto cuya unidad
 * canónica es kg; el ingrediente se guarda en gramos y la agregación de
 * necesidades lo convierte a kg. No se convierte entre dimensiones distintas
 * ni entre unidades discretas (unidad, atado, paquete): eso es un error de
 * datos, nunca una suposición.
 */
final class UnitConverter
{
    /**
     * @var array<string, array{family: string, factor: float}>
     */
    private const UNITS = [
        Unit::Kg->value => ['family' => 'mass', 'factor' => 1000.0],
        Unit::G->value => ['family' => 'mass', 'factor' => 1.0],
        Unit::L->value => ['family' => 'volume', 'factor' => 1000.0],
        Unit::Ml->value => ['family' => 'volume', 'factor' => 1.0],
        Unit::Unidad->value => ['family' => 'unidad', 'factor' => 1.0],
        Unit::Atado->value => ['family' => 'atado', 'factor' => 1.0],
        Unit::Paquete->value => ['family' => 'paquete', 'factor' => 1.0],
    ];

    public static function compatible(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        $source = self::UNITS[$from] ?? null;
        $target = self::UNITS[$to] ?? null;

        return $source !== null
            && $target !== null
            && $source['family'] === $target['family']
            && in_array($source['family'], ['mass', 'volume'], true);
    }

    public static function toCanonical(float $quantity, string $from, string $to): float
    {
        if ($from === $to) {
            return $quantity;
        }

        if (! self::compatible($from, $to)) {
            throw new InvalidArgumentException("No se puede convertir de {$from} a {$to}.");
        }

        $source = self::UNITS[$from];
        $target = self::UNITS[$to];

        return round($quantity * $source['factor'] / $target['factor'], 3);
    }
}
