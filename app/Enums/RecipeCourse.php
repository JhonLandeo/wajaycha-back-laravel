<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Qué papel cumple una receta en una comida (ADR-0021).
 *
 * `main` es el plato principal; `drink` una bebida como quinua, siete semillas
 * o soya; `side` un acompañamiento que no es bebida. El sugeridor usa el tipo
 * para no proponer una bebida sola en el desayuno.
 */
enum RecipeCourse: string
{
    case Main = 'main';
    case Drink = 'drink';
    case Side = 'side';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'Plato principal',
            self::Drink => 'Bebida',
            self::Side => 'Acompañamiento',
        };
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(fn (self $course): string => $course->value, self::cases());
    }
}
