<?php

declare(strict_types=1);

namespace App\Enums;

enum MealEntryKind: string
{
    case Recipe = 'recipe';
    case Leftover = 'leftover';
    case EatOut = 'eat_out';
    case Free = 'free';

    public function label(): string
    {
        return match ($this) {
            self::Recipe => 'Receta',
            self::Leftover => 'Sobras',
            self::EatOut => 'Comer fuera',
            self::Free => 'Comida libre',
        };
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(fn (self $kind): string => $kind->value, self::cases());
    }
}
