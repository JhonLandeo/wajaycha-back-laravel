<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Perfil de alimentación del usuario (ADR-0024).
 *
 * `vegetarian` y `vegan` filtran recetas por etiqueta; `high_protein` y
 * `light` solo priorizan. No hay conteo de calorías: el perfil es un estilo.
 */
enum DietProfile: string
{
    case Balanced = 'balanced';
    case Vegetarian = 'vegetarian';
    case Vegan = 'vegan';
    case HighProtein = 'high_protein';
    case Light = 'light';

    public function label(): string
    {
        return match ($this) {
            self::Balanced => 'Equilibrado',
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
        return array_map(fn (self $profile): string => $profile->value, self::cases());
    }
}
