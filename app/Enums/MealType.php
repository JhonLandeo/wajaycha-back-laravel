<?php

declare(strict_types=1);

namespace App\Enums;

enum MealType: string
{
    case Breakfast = 'breakfast';
    case Lunch = 'lunch';
    case Dinner = 'dinner';

    public function label(): string
    {
        return match ($this) {
            self::Breakfast => 'Desayuno',
            self::Lunch => 'Almuerzo',
            self::Dinner => 'Cena',
        };
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(fn (self $meal): string => $meal->value, self::cases());
    }
}
