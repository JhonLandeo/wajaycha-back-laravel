<?php

declare(strict_types=1);

namespace App\Enums;

enum MealEntryStatus: string
{
    case Planned = 'planned';
    case Cooked = 'cooked';
    case Leftover = 'leftover';
    case AteOut = 'ate_out';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Pendiente',
            self::Cooked => 'Cociné',
            self::Leftover => 'Comí sobras',
            self::AteOut => 'Comí fuera',
            self::Skipped => 'No cociné',
        };
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
