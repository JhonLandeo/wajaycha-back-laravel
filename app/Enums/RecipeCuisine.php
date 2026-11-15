<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Cocina de la receta (ADR-0028). El sugeridor prefiere la peruana cuando
 * encaja con el resto de las reglas.
 */
enum RecipeCuisine: string
{
    case Peruvian = 'peruvian';
    case International = 'international';

    public function label(): string
    {
        return match ($this) {
            self::Peruvian => 'Peruana',
            self::International => 'Internacional',
        };
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(fn (self $cuisine): string => $cuisine->value, self::cases());
    }
}
