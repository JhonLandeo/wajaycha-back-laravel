<?php

declare(strict_types=1);

namespace App\Enums;

enum MenuStrategy: string
{
    case Balanced = 'balanced';
    case UsePantry = 'use_pantry';
    case SaveMoney = 'save_money';

    public function label(): string
    {
        return match ($this) {
            self::Balanced => 'Equilibrado',
            self::UsePantry => 'Vaciar despensa',
            self::SaveMoney => 'Ahorrar',
        };
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(fn (self $strategy): string => $strategy->value, self::cases());
    }
}
