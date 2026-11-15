<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Etiquetas de una receta para el perfil de alimentación (ADR-0024).
 *
 * `vegetarian` implica que no lleva carne ni pescado; `vegan`, que tampoco
 * huevos ni lácteos. `high_protein` y `light` son preferencias, no filtros.
 */
enum DietTag: string
{
    case Vegetarian = 'vegetarian';
    case Vegan = 'vegan';
    case HighProtein = 'high_protein';
    case Light = 'light';

    public function label(): string
    {
        return match ($this) {
            self::Vegetarian => 'Vegetariano',
            self::Vegan => 'Vegano',
            self::HighProtein => 'Alto en proteína',
            self::Light => 'Ligero',
        };
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(fn (self $tag): string => $tag->value, self::cases());
    }
}
